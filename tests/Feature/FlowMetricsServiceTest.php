<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Services\FlowMetricsService;
use Carbon\Carbon;

/**
 * The clock behind every flow metric (issue #145): cycle time from the
 * first entry into Pronto to the last entry into Feito. Every fixture here
 * builds the history explicitly, because that history — not any column on
 * `activities` — is what the service reads.
 */

/**
 * Give an activity a hand-written status history, wiping whatever the
 * observer recorded so the fixture states one sequence only.
 *
 * @param  list<array{0: ActivityStatus, 1: string}>  $steps  [status, moment] pairs, in order.
 */
function withFlowHistory(Activity $activity, array $steps): Activity
{
    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    $previous = null;

    foreach ($steps as [$status, $at]) {
        ActivityStatusChange::factory()->create([
            'activity_id' => $activity->id,
            'from_status' => $previous,
            'to_status' => $status,
            'changed_at' => Carbon::parse($at),
        ]);

        $previous = $status;
    }

    return $activity;
}

/**
 * A concluded activity whose clock ran for exactly $days days, completed
 * today so it always lands inside the current baseline.
 */
function concludedIn(float $days): Activity
{
    $finishedAt = Carbon::parse('2026-08-07 12:00');

    $activity = Activity::factory()->issue()->done()->create([
        'completed_at' => $finishedAt,
    ]);

    return withFlowHistory($activity, [
        [ActivityStatus::Todo, $finishedAt->copy()->subDays($days)->toDateTimeString()],
        [ActivityStatus::Done, $finishedAt->toDateTimeString()],
    ]);
}

function flow(): FlowMetricsService
{
    return app(FlowMetricsService::class);
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00');
    BaselineCut::query()->delete();
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-01-01 00:00')]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('cycle time runs from the first Pronto to the last Feito', function () {
    $activity = withFlowHistory(Activity::factory()->issue()->done()->create(), [
        [ActivityStatus::Backlog, '2026-07-01 09:00'],
        [ActivityStatus::Todo, '2026-07-02 09:00'],
        [ActivityStatus::Doing, '2026-07-03 09:00'],
        [ActivityStatus::Done, '2026-07-06 09:00'],
    ]);

    expect(flow()->cycleTimeDays($activity))->toBe(4.0);
});

test('Aguardando aprovação stays outside the clock because it happens before Pronto', function () {
    // Ten days sitting on the client's desk before the work was even
    // committed to. The clock starts when it becomes Pronto.
    $activity = withFlowHistory(Activity::factory()->issue()->done()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-01 09:00'],
        [ActivityStatus::Todo, '2026-07-11 09:00'],
        [ActivityStatus::Done, '2026-07-13 09:00'],
    ]);

    expect(flow()->cycleTimeDays($activity))->toBe(2.0);
});

test('Esperando and Aguardando validação are inside the clock', function () {
    $activity = withFlowHistory(Activity::factory()->issue()->done()->create(), [
        [ActivityStatus::Todo, '2026-07-01 09:00'],
        [ActivityStatus::Doing, '2026-07-02 09:00'],
        [ActivityStatus::Waiting, '2026-07-03 09:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-06 09:00'],
        [ActivityStatus::Done, '2026-07-09 09:00'],
    ]);

    // The two waits account for six of the eight days, and they count.
    expect(flow()->cycleTimeDays($activity))->toBe(8.0);
});

test('a round trip does not restart the clock — the last Feito closes it', function () {
    $activity = withFlowHistory(Activity::factory()->issue()->done()->create(), [
        [ActivityStatus::Todo, '2026-07-01 09:00'],
        [ActivityStatus::Done, '2026-07-03 09:00'],
        [ActivityStatus::Doing, '2026-07-04 09:00'],
        [ActivityStatus::Todo, '2026-07-05 09:00'],
        [ActivityStatus::Done, '2026-07-10 09:00'],
    ]);

    expect(flow()->cycleTimeDays($activity))->toBe(9.0);
});

test('an item that never reached Pronto has no cycle time rather than a zero', function () {
    $activity = withFlowHistory(Activity::factory()->issue()->done()->create(), [
        [ActivityStatus::Inbox, '2026-07-01 09:00'],
        [ActivityStatus::Done, '2026-07-02 09:00'],
    ]);

    expect(flow()->cycleTimeDays($activity))->toBeNull()
        ->and(flow()->sample())->toBe([]);
});

test('the baseline only counts what was concluded after the last cut', function () {
    $before = Activity::factory()->issue()->done()->create(['completed_at' => Carbon::parse('2026-02-01 09:00')]);
    withFlowHistory($before, [
        [ActivityStatus::Todo, '2026-01-20 09:00'],
        [ActivityStatus::Done, '2026-02-01 09:00'],
    ]);

    concludedIn(3);

    // A cut placed after the older item drops it from the population.
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-03-01 00:00')]);

    expect(flow()->sample())->toBe([3.0]);
});

test('the SLE is unusable below the configured minimum and the surfaces say so', function () {
    config()->set('soloboard.sle_minimum_sample', 30);

    collect(range(1, 7))->each(fn (int $days) => concludedIn($days));

    expect(flow()->sampleSize())->toBe(7)
        ->and(flow()->isUsable())->toBeFalse()
        ->and(flow()->sleDays())->toBeNull()
        ->and(flow()->label())->toBe('amostra pequena (n=7)');
});

test('with enough items the SLE is the configured percentile, rounded up to whole days', function () {
    config()->set('soloboard.sle_minimum_sample', 30);
    config()->set('soloboard.sle_percentile', 85);

    // 1..30 days: the 85th percentile of that sample is 25.65 days.
    collect(range(1, 30))->each(fn (int $days) => concludedIn($days));

    expect(flow()->isUsable())->toBeTrue()
        ->and(flow()->percentileOf(flow()->sample(), 85))->toBe(25.65)
        ->and(flow()->sleDays())->toBe(26)
        ->and(flow()->label())->toBe('SLE 85% ≤ 26d');
});

test('the percentile follows config rather than being hardcoded at 85', function () {
    config()->set('soloboard.sle_minimum_sample', 30);
    config()->set('soloboard.sle_percentile', 50);

    collect(range(1, 30))->each(fn (int $days) => concludedIn($days));

    expect(flow()->sleDays())->toBe(16)
        ->and(flow()->label())->toBe('SLE 50% ≤ 16d');
});

test('a cut without a motivo is refused by the service, not only by the form', function () {
    expect(fn () => flow()->cut('   '))->toThrow(InvalidArgumentException::class);

    $cut = flow()->cut('  Troquei o jeito de trabalhar  ');

    expect($cut->reason)->toBe('Troquei o jeito de trabalhar')
        ->and(flow()->lastCut()->is($cut))->toBeTrue();
});

test('the baseline is scoped by the last Feito, not by completed_at', function () {
    // The two timestamps disagree — an import, a backfill or a bulk write
    // that skipped model events. The history is the authority, because it
    // is also what closes the clock.
    $stampedBeforeFinishedAfter = Activity::factory()->issue()->done()->create([
        'completed_at' => Carbon::parse('2026-02-01 09:00'),
    ]);
    withFlowHistory($stampedBeforeFinishedAfter, [
        [ActivityStatus::Todo, '2026-04-01 09:00'],
        [ActivityStatus::Done, '2026-04-04 09:00'],
    ]);

    $stampedAfterFinishedBefore = Activity::factory()->issue()->done()->create([
        'completed_at' => Carbon::parse('2026-05-01 09:00'),
    ]);
    withFlowHistory($stampedAfterFinishedBefore, [
        [ActivityStatus::Todo, '2026-02-01 09:00'],
        [ActivityStatus::Done, '2026-02-08 09:00'],
    ]);

    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-03-01 00:00')]);

    // Only the one that actually entered Feito after the cut: 3 days.
    // Filtering by completed_at would have picked the other one, and
    // reported 7.
    expect(flow()->sample())->toBe([3.0]);
});

test('an item reopened after the cut leaves the baseline until it is done again', function () {
    $reopened = Activity::factory()->issue()->doing()->create();
    withFlowHistory($reopened, [
        [ActivityStatus::Todo, '2026-07-01 09:00'],
        [ActivityStatus::Done, '2026-07-05 09:00'],
        [ActivityStatus::Doing, '2026-07-06 09:00'],
    ]);

    // It has a Feito after the cut, but it is work in progress again —
    // measuring it would report a delivery that is currently undone.
    expect(flow()->sample())->toBe([]);
});

test('a cut invalidates the memoized sample on the same instance', function () {
    concludedIn(3);

    $flow = flow();

    expect($flow->sample())->toBe([3.0]);

    // A second past the only conclusion, so the population really is empty
    // — the boundary itself is inclusive.
    $flow->cut('Mudei o jeito de trabalhar', now()->copy()->addSecond());

    // The cut lands after the only concluded item, so the population is
    // now empty. Reading a stale [3.0] here would keep quoting a promise
    // the user has just retired.
    expect($flow->sample())->toBe([])
        ->and($flow->sampleSize())->toBe(0);
});

test('the tooltip rounds away from the threshold, so it never contradicts the border', function (float $ageDays, string $level, string $tooltip) {
    config()->set('soloboard.sle_minimum_sample', 30);

    collect(range(1, 30))->each(fn () => concludedIn(10));

    $activity = Activity::factory()->issue()->doing()->create();
    withFlowHistory($activity, [
        [ActivityStatus::Todo, now()->copy()->subMinutes((int) round($ageDays * 24 * 60))->toDateTimeString()],
    ]);

    $aging = flow()->agingFor($activity);

    expect(flow()->sleDays())->toBe(10)
        ->and($aging['level'])->toBe($level)
        ->and($aging['tooltip'])->toBe($tooltip);
})->with([
    // Amber: still inside the SLE, and the text must not say it reached it.
    'well inside' => [8.0, 'attention', '8 de 10 dias da SLE'],
    'just inside' => [9.6, 'attention', '9,6 de 10 dias da SLE'],
    'a hair inside' => [9.96, 'attention', '9,9 de 10 dias da SLE'],
    // Red: broken, and the text must not read as short of the SLE.
    'exactly at the SLE' => [10.0, 'breach', '10 de 10 dias da SLE'],
    'a hair over' => [10.04, 'breach', '10,1 de 10 dias da SLE'],
    'well over' => [10.4, 'breach', '10,4 de 10 dias da SLE'],
]);

test('the distribution keeps the empty days between buckets so the tail is visible', function () {
    concludedIn(1);
    concludedIn(1);
    concludedIn(4);

    expect(flow()->distribution())->toBe([
        ['days' => 1, 'count' => 2],
        ['days' => 2, 'count' => 0],
        ['days' => 3, 'count' => 0],
        ['days' => 4, 'count' => 1],
    ]);
});
