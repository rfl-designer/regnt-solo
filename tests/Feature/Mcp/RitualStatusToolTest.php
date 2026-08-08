<?php

use App\Enums\ActivityStatus;
use App\Mcp\Prompts\DevelopmentWorkflowPrompt;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\GetRitualStatusTool;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\MorningRitual;
use Carbon\Carbon;

/**
 * A costura MCP do ritual matinal (issue #147): `get-ritual-status` entra,
 * as três tools do plano diário e o prompt `daily-planning` saem.
 *
 * @return array<string, mixed>
 */
function ritualStatusPayload(): array
{
    $response = SoloBoardServer::tool(GetRitualStatusTool::class, []);

    $response->assertOk();

    $content = (new ReflectionMethod($response, 'content'))->invoke($response);

    return json_decode($content[0], true, flags: JSON_THROW_ON_ERROR);
}

test('get-ritual-status reports a day whose ritual has not been done', function () {
    $payload = ritualStatusPayload();

    // O dia respondido é o do usuário, não o UTC (issue #147, review).
    expect($payload['date'])->toBe(MorningRitual::businessToday()->toDateString())
        ->and($payload['completed'])->toBeFalse()
        ->and($payload['completed_at'])->toBeNull()
        ->and($payload['notes'])->toBeNull();
});

test('get-ritual-status reports the conclusion and the notes', function () {
    $this->travelTo('2026-08-07 11:15:00'); // 08:15 no fuso de negócio

    MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete('Puxei a fatia de billing.');

    $payload = ritualStatusPayload();

    expect($payload['completed'])->toBeTrue()
        ->and($payload['completed_at_label'])->toBe('08:15')
        ->and($payload['notes'])->toBe('Puxei a fatia de billing.');
});

test('get-ritual-status carries the snapshot each step would show', function () {
    config(['soloboard.wip_limit_doing' => 2, 'soloboard.sle_minimum_sample' => 30]);

    Activity::factory()->issue()->done()->create();
    $archived = Activity::factory()->issue()->done()->create();
    $archived->archive();

    Activity::factory()->issue()->awaitingApproval('Cliente')->create();
    Activity::factory()->issue()->waiting('Designer')->create();
    Activity::factory()->issue()->doing()->create();

    $queued = Activity::factory()->issue()->todo()->create();
    ActivityStatusChange::query()->where('activity_id', $queued->id)->delete();
    ActivityStatusChange::factory()->create([
        'activity_id' => $queued->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => Carbon::parse('2026-08-01 09:00'),
    ]);

    $snapshot = ritualStatusPayload()['snapshot'];

    expect($snapshot['done_to_archive'])->toBe(1)
        ->and($snapshot['waiting']['awaiting_approval'])->toBe(1)
        ->and($snapshot['waiting']['waiting'])->toBe(1)
        ->and($snapshot['waiting']['awaiting_validation'])->toBe(0)
        ->and($snapshot['doing'])->toBe(['count' => 1, 'limit' => 2])
        ->and($snapshot['pull_queue']['count'])->toBe(1);
});

test('get-ritual-status refuses to claim calm it has not measured', function () {
    config(['soloboard.sle_minimum_sample' => 30]);

    $aging = Activity::factory()->issue()->doing()->create();
    withSpecHistory($aging, [[ActivityStatus::Todo, now()->subDays(40)->toDateTimeString()]]);

    $aging = ritualStatusPayload()['snapshot']['aging'];

    expect($aging['baseline_usable'])->toBeFalse()
        ->and($aging['sle_days'])->toBeNull()
        // Null, não zero: "nada envelhecendo" e "não dá para dizer" são
        // respostas diferentes.
        ->and($aging['items_past_attention'])->toBeNull()
        ->and($aging['label'])->toContain('amostra pequena');
});

test('the daily plan tools and prompt are gone from the server', function () {
    expect(file_exists(app_path('Mcp/Tools/TodayPlanTool.php')))->toBeFalse()
        ->and(file_exists(app_path('Mcp/Tools/AddToPlanTool.php')))->toBeFalse()
        ->and(file_exists(app_path('Mcp/Tools/SuggestTasksTool.php')))->toBeFalse()
        ->and(file_exists(app_path('Mcp/Prompts/DailyPlanningPrompt.php')))->toBeFalse();

    $server = new ReflectionClass(SoloBoardServer::class);
    $tools = $server->getDefaultProperties()['tools'];
    $prompts = $server->getDefaultProperties()['prompts'];

    expect($tools)->toContain(GetRitualStatusTool::class);

    foreach (array_merge($tools, $prompts) as $registered) {
        expect($registered)->not->toContain('TodayPlan')
            ->and($registered)->not->toContain('AddToPlan')
            ->and($registered)->not->toContain('SuggestTasks')
            ->and($registered)->not->toContain('DailyPlanning');
    }
});

test('no registered prompt instructs a client to use a removed tool', function () {
    // Um prompt que manda usar `add-to-plan` descreve um passo impossível:
    // a tool não existe mais (issue #147, review). O teste lê o texto real
    // de cada prompt registrado, não o registro.
    $removed = ['add-to-plan', 'today-plan', 'suggest-tasks', 'daily-planning'];

    $prompts = (new ReflectionClass(SoloBoardServer::class))->getDefaultProperties()['prompts'];

    expect($prompts)->not->toBeEmpty();

    foreach ($prompts as $promptClass) {
        $source = file_get_contents((new ReflectionClass($promptClass))->getFileName());

        foreach ($removed as $tool) {
            expect($source)->not->toContain("`{$tool}`");
        }
    }
});

test('the workflow prompt points at the tools that still exist', function () {
    $source = file_get_contents(
        (new ReflectionClass(DevelopmentWorkflowPrompt::class))->getFileName()
    );

    expect($source)->toContain('get-ritual-status')
        ->and($source)->toContain('get-pull-queue');
});

test('the workflow documentation matches the tools the server registers', function () {
    $doc = file_get_contents(base_path('docs/DEVELOPMENT_WORKFLOW_PROMPT.md'));

    expect($doc)->not->toContain('`add-to-plan`')
        ->and($doc)->not->toContain('`today-plan`')
        ->and($doc)->not->toContain('`suggest-tasks`')
        ->and($doc)->toContain('`get-ritual-status`');
});
