<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\DoingWipLimitExceededException;
use App\Exceptions\EmergencyRequiresReasonException;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateIssueTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\UpdateIssueTool;
use App\Models\Activity;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

test('update-issue refuses a third item into doing with the canonical WIP message', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $third = Activity::factory()->issue()->todo()->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $third->id,
        'status' => 'doing',
    ]);

    $response->assertHasErrors([DoingWipLimitExceededException::messageFor(2)]);

    expect($third->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('update-issue lets an Emergência into a full doing column', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $emergency = Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $emergency->id,
        'status' => 'doing',
    ]);

    $response->assertOk();

    expect($emergency->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('classifying as emergency without a motivo is refused via MCP', function () {
    $issue = Activity::factory()->issue()->todo()->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'service_class' => 'emergency',
    ]);

    $response->assertHasErrors([EmergencyRequiresReasonException::MESSAGE]);

    expect($issue->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('update-issue classifies an emergency when the motivo is given and echoes it back', function () {
    $issue = Activity::factory()->issue()->todo()->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'service_class' => 'emergency',
        'emergency_reason' => 'Produção fora do ar',
    ]);

    $response->assertOk();
    $response->assertSee('"emergency_reason": "Produ');

    expect($issue->fresh()->service_class)->toBe(ServiceClass::Emergency);
});

test('a second emergency via MCP returns a structured error with the active one embedded', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create([
        'title' => 'Hotfix do checkout',
    ]);
    $active->forceFill(['emergency_since' => now()->subDays(2)])->saveQuietly();

    $second = Activity::factory()->issue()->todo()->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $second->id,
        'service_class' => 'emergency',
        'emergency_reason' => 'Outro incêndio',
    ]);

    $response->assertHasErrors();
    $response->assertSee('single_active_emergency');
    $response->assertSee('"id": '.$active->id);
    $response->assertSee('Hotfix do checkout');
    $response->assertSee('"age_in_days": 2');
    $response->assertSee('There is no force parameter');

    expect($second->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('there is no force parameter anywhere in the emergency-capable tools', function (string $tool) {
    $schema = array_keys((new $tool)->schema(new JsonSchemaTypeFactory));

    // Swapping the emergency must always be two explicit calls — a flag
    // letting a client override the invariant would make it advisory,
    // which is exactly what it must not be.
    expect($schema)->not->toContain('force')
        ->and($schema)->toContain('emergency_reason');
})->with([CreateTaskTool::class, CreateIssueTool::class, UpdateIssueTool::class]);

test('the documented two-call swap works over MCP', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio antigo')->create();
    $next = Activity::factory()->issue()->todo()->create();

    // Call 1: demote the active emergency.
    SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $active->id,
        'service_class' => 'standard',
    ])->assertOk();

    // Call 2: classify the new one.
    SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $next->id,
        'service_class' => 'emergency',
        'emergency_reason' => 'Incêndio novo',
    ])->assertOk();

    expect($active->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($next->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($next->fresh()->emergency_reason)->toBe('Incêndio novo');
});

test('create-task refuses an emergency with no motivo and accepts one with it', function () {
    SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Incêndio sem motivo',
        'service_class' => 'emergency',
    ])->assertHasErrors([EmergencyRequiresReasonException::MESSAGE]);

    SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Incêndio com motivo',
        'service_class' => 'emergency',
        'emergency_reason' => 'Produção fora do ar',
    ])->assertOk();

    expect(Activity::where('title', 'Incêndio sem motivo')->exists())->toBeFalse()
        ->and(Activity::where('title', 'Incêndio com motivo')->first()->emergency_reason)
        ->toBe('Produção fora do ar');
});
