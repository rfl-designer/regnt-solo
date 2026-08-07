<?php

use App\Enums\ActivityStatus;
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

    expect($payload['date'])->toBe(today()->toDateString())
        ->and($payload['completed'])->toBeFalse()
        ->and($payload['completed_at'])->toBeNull()
        ->and($payload['notes'])->toBeNull();
});

test('get-ritual-status reports the conclusion and the notes', function () {
    $this->travelTo('2026-08-07 08:15:00');

    MorningRitual::getOrCreateForDate(today())->complete('Puxei a fatia de billing.');

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
