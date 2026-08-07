<?php

use App\Enums\ActivityStatus;
use App\Enums\PullQueueReason;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Services\PullQueueService;
use Carbon\Carbon;

/**
 * The window that decides "Data fixa em risco" stops being a guess the
 * moment the board can measure one (issue #145). Nothing is switched on by
 * hand: the queue asks for the SLE, and uses the configured N-day fallback
 * only while the SLE is not a promise yet.
 */

/**
 * Fill the baseline with $count concluded items, each with a cycle time of
 * exactly $days days.
 */
function baselineOf(int $count, int $days): void
{
    $finishedAt = Carbon::parse('2026-08-07 10:00');

    for ($i = 0; $i < $count; $i++) {
        $activity = Activity::factory()->issue()->done()->create(['completed_at' => $finishedAt]);

        ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

        ActivityStatusChange::factory()->create([
            'activity_id' => $activity->id,
            'to_status' => ActivityStatus::Todo,
            'changed_at' => $finishedAt->copy()->subDays($days),
        ]);

        ActivityStatusChange::factory()->create([
            'activity_id' => $activity->id,
            'from_status' => ActivityStatus::Todo,
            'to_status' => ActivityStatus::Done,
            'changed_at' => $finishedAt,
        ]);
    }
}

function pullQueue(): PullQueueService
{
    return app(PullQueueService::class);
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00');
    BaselineCut::query()->delete();
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-01-01 00:00')]);

    config()->set('soloboard.fixed_date_risk_days', 7);
    config()->set('soloboard.sle_minimum_sample', 30);
    config()->set('soloboard.sle_percentile', 85);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('with a small sample the queue keeps the configured N-day window', function () {
    baselineOf(10, days: 20);

    expect(pullQueue()->riskWindowDays())->toBe(7)
        ->and(pullQueue()->riskWindowFromSle())->toBeFalse();
});

test('the queue swaps to the SLE automatically once the baseline is big enough', function () {
    baselineOf(30, days: 20);

    expect(pullQueue()->riskWindowDays())->toBe(20)
        ->and(pullQueue()->riskWindowFromSle())->toBeTrue();
});

test('the wider SLE window promotes a Data fixa the N-day window would have left in FIFO', function () {
    $fixedDate = Activity::factory()->issue()->todo()
        ->fixedDate(today()->addDays(12)->toDateString())
        ->create();

    // 12 days out is comfortably beyond the 7-day guess.
    expect(pullQueue()->isAtRisk($fixedDate))->toBeFalse();

    baselineOf(30, days: 20);

    // Measured reality: this board takes 20 days, so 12 is already late.
    expect(pullQueue()->isAtRisk($fixedDate->fresh()))->toBeTrue()
        ->and(pullQueue()->queue()->first()->reason)->toBe(PullQueueReason::FixedDateAtRisk);
});

test('a cut that empties the baseline sends the queue back to the N-day window', function () {
    baselineOf(30, days: 20);

    expect(pullQueue()->riskWindowFromSle())->toBeTrue();

    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-08-07 11:00')]);

    expect(pullQueue()->riskWindowDays())->toBe(7)
        ->and(pullQueue()->riskWindowFromSle())->toBeFalse();
});
