<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\GetPitchTool;
use App\Mcp\Tools\PromoteDraftTool;
use App\Models\Activity;
use App\Models\Project;
use App\Services\ShapingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The MCP seam of shaping (issue #148).
 *
 * The point of these tests is that the seam has no rules of its own: every
 * "não" here is the shaping page's "não", produced by the same service, and
 * nothing on this path reaches GitHub.
 */
test('promote-draft refuses an unshaped idea with the same message as the UI', function () {
    $draft = Activity::factory()->draft()->create(['description' => null, 'spec' => null]);
    $project = Project::factory()->create();

    $response = SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $draft->id,
        'project_id' => $project->id,
    ]);

    $response->assertHasErrors();
    $response->assertSee('Para promover a Ideia a Épico, falta: Dor, Apetite e Esboço.');

    expect($draft->refresh()->type)->toBe(ActivityType::Draft);
});

test('promote-draft refuses a shaped idea with no project, naming the project', function () {
    $draft = Activity::factory()->draft()->shaped()->create();

    $response = SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $draft->id,
    ]);

    $response->assertHasErrors();
    $response->assertSee('falta: Projeto.');

    expect($draft->refresh()->type)->toBe(ActivityType::Draft);
});

test('promote-draft refuses a project that is not active, exactly as the page does', function () {
    $draft = Activity::factory()->draft()->shaped()->create();
    $archived = Project::factory()->archived()->create();

    $response = SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $draft->id,
        'project_id' => $archived->id,
    ]);

    // Existe, mas não é um projeto onde se abre uma aposta nova — e a recusa é
    // a mesma palavra que o botão usa.
    $response->assertHasErrors();
    $response->assertSee('falta: Projeto.');

    expect($draft->refresh()->type)->toBe(ActivityType::Draft);
});

test('promote-draft promotes in place, in the SoloBoard, without touching GitHub', function () {
    $project = Project::factory()->create();
    $draft = Activity::factory()->draft()->shaped(21)->create(['title' => 'Painel de políticas']);

    $response = SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $draft->id,
        'project_id' => $project->id,
    ]);

    $response->assertOk();
    $response->assertSee('"promoted": true');
    // A antiga promoção era um handoff para o GitHub; não é mais.
    $response->assertDontSee('/to-prd');
    $response->assertDontSee('/to-issues');
    $response->assertDontSee('github');

    expect($draft->refresh())
        ->type->toBe(ActivityType::Epic)
        ->status->toBe(ActivityStatus::Backlog)
        ->project_id->toBe($project->id)
        ->appetite_days->toBe(21)
        ->github_issue_number->toBeNull()
        ->specStage()->toBeNull();
});

test('promote-draft accepts a project slug as well as an id', function () {
    $project = Project::factory()->create(['slug' => 'soloboard']);
    $draft = Activity::factory()->draft()->shaped()->create();

    SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $draft->id,
        'project_slug' => 'soloboard',
    ])->assertOk();

    expect($draft->refresh()->project_id)->toBe($project->id);
});

test('promote-draft keeps using the project the draft already has', function () {
    $project = Project::factory()->create();
    $draft = Activity::factory()->draft()->shaped()->create(['project_id' => $project->id]);

    SoloBoardServer::tool(PromoteDraftTool::class, ['draft_id' => $draft->id])->assertOk();

    expect($draft->refresh()->type)->toBe(ActivityType::Epic);
});

test('promote-draft refuses anything that is not an idea', function () {
    $epic = Activity::factory()->epic()->create();

    expect(fn () => SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $epic->id,
    ]))->toThrow(ModelNotFoundException::class);

    expect($epic->refresh()->status)->not->toBeNull();
});

test('get-pitch returns exactly what the app copies', function () {
    $draft = Activity::factory()->draft()->create([
        'title' => 'Fila de puxar',
        'description' => 'Não sei o que pegar depois de terminar algo.',
        'appetite_days' => 7,
        'spec' => 'Pronto se auto-ordena.',
    ]);

    $response = SoloBoardServer::tool(GetPitchTool::class, ['activity_id' => $draft->id]);

    $response->assertOk();
    $response->assertSee(app(ShapingService::class)->pitch($draft));
});

test('get-pitch renders a promoted epic the same way it rendered the idea', function () {
    $project = Project::factory()->create();
    $draft = Activity::factory()->draft()->shaped()->create(['title' => 'Mesma aposta']);

    $before = app(ShapingService::class)->pitch($draft);

    SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $draft->id,
        'project_id' => $project->id,
    ])->assertOk();

    SoloBoardServer::tool(GetPitchTool::class, ['activity_id' => $draft->id])
        ->assertOk()
        ->assertSee($before);
});

test('get-pitch refuses an activity that is neither an idea nor an epic', function () {
    $issue = Activity::factory()->issue()->create();

    expect(fn () => SoloBoardServer::tool(GetPitchTool::class, ['activity_id' => $issue->id]))
        ->toThrow(ModelNotFoundException::class);
});
