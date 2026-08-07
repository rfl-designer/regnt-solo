<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Services\FlowMetricsService;
use Carbon\Carbon;

/**
 * Flow efficiency per Spec and the ranking of client waits (issue #146).
 * Both are derived from ActivityStatusChange, so every fixture writes the
 * history by hand — see {@see withSpecHistory()} in SpecLifecycleTest.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function metrics(): FlowMetricsService
{
    return app(FlowMetricsService::class);
}

test('the window runs from the approval to the validation, with the approval itself outside it', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->done()->create(), [
        // Ten days on the client's desk: outside the window entirely.
        [ActivityStatus::AwaitingApproval, '2026-07-01 00:00'],
        [ActivityStatus::Todo, '2026-07-11 00:00'],
        [ActivityStatus::Done, '2026-07-21 00:00'],
    ]);

    // Atomic Épico: its own Fazendo is the touch. It has none here.
    $efficiency = metrics()->specEfficiency($epic);

    expect($efficiency['window_minutes'])->toBe(10.0 * 24 * 60)
        ->and($efficiency['open'])->toBeFalse()
        ->and($efficiency['touch_minutes'])->toBe(0.0)
        ->and($efficiency['percent'])->toBe(0);
});

test('an unvalidated Spec keeps its window running to now, so waiting costs it', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->awaitingValidation()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 12:00'],
        [ActivityStatus::Doing, '2026-08-03 12:00'],
        [ActivityStatus::AwaitingValidation, '2026-08-04 12:00'],
    ]);

    $efficiency = metrics()->specEfficiency($epic);

    // Approved on the 3rd, still open now (the 7th): four days of window,
    // one of which was spent in Fazendo.
    expect($efficiency['open'])->toBeTrue()
        ->and($efficiency['window_minutes'])->toBe(4.0 * 24 * 60)
        ->and($efficiency['touch_minutes'])->toBe(1.0 * 24 * 60)
        ->and($efficiency['percent'])->toBe(25);
});

test('a Spec never approved has no efficiency rather than a zero', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->awaitingApproval()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 12:00'],
    ]);

    expect(metrics()->specEfficiency($epic))->toBeNull();
});

test('the touch is the union of the children, so two at once never pass 100%', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->doing()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 00:00'],
        [ActivityStatus::Todo, '2026-08-03 00:00'],
    ]);

    // Both children sit in Fazendo across exactly the same four days. Summed
    // that would be eight days of touch inside a four-day window — 200%.
    foreach (range(1, 2) as $ignored) {
        withSpecHistory(
            Activity::factory()->issue()->doing()->create(['parent_id' => $epic->id]),
            [
                [ActivityStatus::Todo, '2026-08-03 00:00'],
                [ActivityStatus::Doing, '2026-08-03 00:00'],
                [ActivityStatus::Waiting, '2026-08-07 00:00'],
            ]
        );
    }

    $efficiency = metrics()->specEfficiency($epic->fresh());

    // Window: 2026-08-03 00:00 -> now (2026-08-07 12:00) = 4.5 days.
    // Touch: the union of the two, i.e. 4 days, not 8.
    expect($efficiency['window_minutes'])->toBe(4.5 * 24 * 60)
        ->and($efficiency['touch_minutes'])->toBe(4.0 * 24 * 60)
        ->and($efficiency['ratio'])->toBeLessThanOrEqual(1.0)
        ->and($efficiency['percent'])->toBe(89);
});

test('partially overlapping children are fused rather than double counted', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->doing()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 00:00'],
        [ActivityStatus::Todo, '2026-08-02 00:00'],
    ]);

    withSpecHistory(Activity::factory()->issue()->todo()->create(['parent_id' => $epic->id]), [
        [ActivityStatus::Doing, '2026-08-02 00:00'],
        [ActivityStatus::Todo, '2026-08-04 00:00'],
    ]);

    withSpecHistory(Activity::factory()->issue()->todo()->create(['parent_id' => $epic->id]), [
        [ActivityStatus::Doing, '2026-08-03 00:00'],
        [ActivityStatus::Todo, '2026-08-05 00:00'],
    ]);

    $efficiency = metrics()->specEfficiency($epic->fresh());

    // 02->04 fused with 03->05 covers 02->05: three days, not four.
    expect($efficiency['touch_minutes'])->toBe(3.0 * 24 * 60);
});

test('touch outside the window is clipped away', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->done()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-01 00:00'],
        [ActivityStatus::Todo, '2026-07-05 00:00'],
        [ActivityStatus::Done, '2026-07-07 00:00'],
    ]);

    // Started before the approval and ran past the validation. Only the two
    // days inside the window may count.
    withSpecHistory(Activity::factory()->issue()->todo()->create(['parent_id' => $epic->id]), [
        [ActivityStatus::Doing, '2026-07-02 00:00'],
        [ActivityStatus::Todo, '2026-07-09 00:00'],
    ]);

    $efficiency = metrics()->specEfficiency($epic->fresh());

    expect($efficiency['window_minutes'])->toBe(2.0 * 24 * 60)
        ->and($efficiency['touch_minutes'])->toBe(2.0 * 24 * 60)
        ->and($efficiency['percent'])->toBe(100);
});

test('the client ranking sums both client waits and leaves the internal one out', function () {
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create(['client_id' => $client->id]);

    withSpecHistory(Activity::factory()->issue()->doing()->create(['project_id' => $project->id]), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 00:00'],  // 1 dia
        [ActivityStatus::Todo, '2026-08-02 00:00'],
        [ActivityStatus::Waiting, '2026-08-03 00:00'],           // 3 dias, internos
        [ActivityStatus::AwaitingValidation, '2026-08-06 00:00'], // 1 dia
        [ActivityStatus::Doing, '2026-08-07 00:00'],
    ]);

    $ranking = metrics()->clientWaitRanking();

    expect($ranking)->toHaveCount(1)
        ->and($ranking[0]['client']->is($client))->toBeTrue()
        ->and($ranking[0]['items'])->toBe(1)
        // Two days of client wait. The three days of Esperando are not the
        // client's to answer for.
        ->and($ranking[0]['minutes'])->toBe(2.0 * 24 * 60);
});

test('the ranking is decreasing and counts the items behind each total', function () {
    $slow = Client::factory()->create(['name' => 'Lenta']);
    $fast = Client::factory()->create(['name' => 'Rápida']);

    withSpecHistory(Activity::factory()->epic()->todo()->create(['client_id' => $slow->id]), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 00:00'],
        [ActivityStatus::Todo, '2026-08-06 00:00'],
    ]);
    withSpecHistory(Activity::factory()->issue()->todo()->create(['client_id' => $slow->id]), [
        [ActivityStatus::AwaitingApproval, '2026-08-05 00:00'],
        [ActivityStatus::Todo, '2026-08-07 00:00'],
    ]);
    withSpecHistory(Activity::factory()->issue()->todo()->create(['client_id' => $fast->id]), [
        [ActivityStatus::AwaitingApproval, '2026-08-06 00:00'],
        [ActivityStatus::Todo, '2026-08-06 12:00'],
    ]);

    $ranking = metrics()->clientWaitRanking();

    expect($ranking->pluck('client.name')->all())->toBe(['Lenta', 'Rápida'])
        ->and($ranking[0]['items'])->toBe(2)
        ->and($ranking[0]['minutes'])->toBe(7.0 * 24 * 60)
        ->and($ranking[1]['items'])->toBe(1)
        ->and($ranking[1]['minutes'])->toBe(0.5 * 24 * 60);
});

test('a wait still open counts up to now, and one older than the window only counts its share', function () {
    $client = Client::factory()->create(['name' => 'Acme']);

    // Sent 40 days ago and never answered: only the last 30 days count.
    withSpecHistory(Activity::factory()->issue()->awaitingApproval()->create(['client_id' => $client->id]), [
        [ActivityStatus::AwaitingApproval, now()->copy()->subDays(40)->toDateTimeString()],
    ]);

    $ranking = metrics()->clientWaitRanking(30);

    expect($ranking[0]['minutes'])->toBe(30.0 * 24 * 60);
});

test('waits with no client to attribute them to are left out of the ranking', function () {
    withSpecHistory(Activity::factory()->issue()->awaitingApproval()->create(['client_id' => null]), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 00:00'],
    ]);

    expect(metrics()->clientWaitRanking())->toBeEmpty();
});
