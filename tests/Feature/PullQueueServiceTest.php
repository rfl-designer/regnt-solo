<?php

use App\Enums\ActivityStatus;
use App\Enums\PullQueueReason;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Services\PullQueueService;
use Carbon\Carbon;

/**
 * The pull queue's ordering (issue #144). Every test here builds the
 * history through ActivityStatusChange, because "last entry into Pronto"
 * is derived from that history and not from any column of `activities`.
 */

/**
 * Put an activity in Pronto and pin exactly when it got there, wiping the
 * observer-recorded history first so the fixture states one fact only.
 */
function readyAt(Activity $activity, string $at): Activity
{
    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => Carbon::parse($at),
    ]);

    return $activity;
}

/**
 * @return list<int>
 */
function queuedIds(): array
{
    return app(PullQueueService::class)->queue()->map(fn ($entry): int => $entry->activity->id)->all();
}

test('the queue orders emergency, then fixed dates at risk by due date, then FIFO', function () {
    $oldFifo = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');
    $newFifo = readyAt(Activity::factory()->issue()->todo()->intangible()->create(), '2026-08-05 09:00');

    $riskFar = readyAt(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(5)->toDateString())->create(),
        '2026-08-02 09:00'
    );
    $riskNear = readyAt(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(1)->toDateString())->create(),
        '2026-08-06 09:00'
    );

    $emergency = readyAt(
        Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create(),
        '2026-08-07 09:00'
    );

    expect(queuedIds())->toBe([
        $emergency->id,
        $riskNear->id,
        $riskFar->id,
        $oldFifo->id,
        $newFifo->id,
    ]);
});

test('a Data fixa outside the risk window waits its turn in the FIFO degrau', function () {
    $earlyStandard = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');
    $lateFixedDate = readyAt(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(30)->toDateString())->create(),
        '2026-08-04 09:00'
    );

    $queue = app(PullQueueService::class)->queue();

    expect($queue->pluck('reason')->all())->toBe([PullQueueReason::Fifo, PullQueueReason::Fifo]);
    expect(queuedIds())->toBe([$earlyStandard->id, $lateFixedDate->id]);
});

test('an overdue Data fixa is at risk and leads the fixed-date degrau', function () {
    $future = readyAt(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(2)->toDateString())->create(),
        '2026-08-01 09:00'
    );
    $overdue = readyAt(
        Activity::factory()->issue()->todo()->fixedDate(today()->subDays(3)->toDateString())->create(),
        '2026-08-02 09:00'
    );

    expect(queuedIds())->toBe([$overdue->id, $future->id]);
});

test('the risk window comes from config', function () {
    config()->set('soloboard.fixed_date_risk_days', 2);

    $fifo = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');
    $fixedDate = readyAt(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(5)->toDateString())->create(),
        '2026-08-02 09:00'
    );

    expect(queuedIds())->toBe([$fifo->id, $fixedDate->id]);

    config()->set('soloboard.fixed_date_risk_days', 7);

    expect(queuedIds())->toBe([$fixedDate->id, $fifo->id]);
});

test('an item that leaves and comes back to Pronto rejoins at the end of the FIFO', function () {
    $first = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');
    $second = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-02 09:00');

    expect(queuedIds())->toBe([$first->id, $second->id]);

    // The round trip: pulled into Fazendo, then pushed back to Pronto.
    ActivityStatusChange::factory()->create([
        'activity_id' => $first->id,
        'from_status' => ActivityStatus::Todo,
        'to_status' => ActivityStatus::Doing,
        'changed_at' => Carbon::parse('2026-08-03 09:00'),
    ]);
    ActivityStatusChange::factory()->create([
        'activity_id' => $first->id,
        'from_status' => ActivityStatus::Doing,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => Carbon::parse('2026-08-04 09:00'),
    ]);

    expect(queuedIds())->toBe([$second->id, $first->id]);
});

test('an item with no Pronto history falls back to created_at', function () {
    $withHistory = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-05 09:00');

    $noHistory = Activity::factory()->issue()->todo()->create([
        'created_at' => Carbon::parse('2026-08-01 09:00'),
    ]);
    ActivityStatusChange::query()->where('activity_id', $noHistory->id)->delete();

    expect(queuedIds())->toBe([$noHistory->id, $withHistory->id]);
});

test('two entries into Pronto in the same second keep the order they were recorded in', function () {
    // `changed_at` is a dateTime (second precision), so the timestamps are
    // identical and only the status-change id can tell the two moves
    // apart. The older activity is the one that moved *second*, which is
    // exactly the case an activity-id tie-break would invert.
    $older = Activity::factory()->issue()->todo()->create(['title' => 'Voltou depois']);
    $newer = Activity::factory()->issue()->todo()->create(['title' => 'Voltou antes']);

    ActivityStatusChange::query()->whereIn('activity_id', [$older->id, $newer->id])->delete();

    foreach ([$newer, $older] as $activity) {
        ActivityStatusChange::factory()->create([
            'activity_id' => $activity->id,
            'from_status' => ActivityStatus::Doing,
            'to_status' => ActivityStatus::Todo,
            'changed_at' => Carbon::parse('2026-08-04 09:00:00'),
        ]);
    }

    expect(queuedIds())->toBe([$newer->id, $older->id]);
});

test('FIFO ties are broken deterministically by id', function () {
    $a = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');
    $b = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');
    $c = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');

    expect(queuedIds())->toBe([$a->id, $b->id, $c->id]);
});

test('the queue universe is the leaf items in Pronto', function () {
    $issue = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');
    $atomicEpic = readyAt(Activity::factory()->epic()->create(['status' => ActivityStatus::Todo]), '2026-08-02 09:00');

    $parentEpic = Activity::factory()->epic()->create(['status' => ActivityStatus::Todo]);
    Activity::factory()->issue()->backlog()->create(['parent_id' => $parentEpic->id]);

    Activity::factory()->issue()->backlog()->create();
    Activity::factory()->issue()->doing()->create();

    expect(queuedIds())->toBe([$issue->id, $atomicEpic->id]);
});

test('the queue can be scoped by the callers filters', function () {
    $kept = readyAt(Activity::factory()->issue()->todo()->intangible()->create(), '2026-08-01 09:00');
    readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-02 09:00');

    $queue = app(PullQueueService::class)->queue(
        fn ($query) => $query->where('service_class', 'intangible')
    );

    expect($queue->map(fn ($entry): int => $entry->activity->id)->all())->toBe([$kept->id]);
});

test('each entry carries the motivo of its position', function () {
    $emergency = readyAt(
        Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create(),
        '2026-08-07 09:00'
    );
    $atRisk = readyAt(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(3)->toDateString())->create(),
        '2026-08-06 09:00'
    );
    $fifo = readyAt(Activity::factory()->issue()->todo()->create(), '2026-08-05 09:00');

    $reasons = app(PullQueueService::class)
        ->queue()
        ->mapWithKeys(fn ($entry): array => [$entry->activity->id => $entry->positionReason()]);

    expect($reasons[$emergency->id])->toBe('Emergência: Produção fora do ar');
    expect($reasons[$atRisk->id])->toBe('em risco: faltam 3 dias');
    expect($reasons[$fifo->id])->toStartWith('ordem de chegada: em Pronto ');
});

test('a Data fixa due today reads "vence hoje"', function () {
    readyAt(
        Activity::factory()->issue()->todo()->fixedDate(today()->toDateString())->create(),
        '2026-08-06 09:00'
    );

    expect(app(PullQueueService::class)->queue()->first()->positionReason())->toBe('em risco: vence hoje');
});

test('an overdue Data fixa reads how late it is', function () {
    readyAt(
        Activity::factory()->issue()->todo()->fixedDate(today()->subDay()->toDateString())->create(),
        '2026-08-06 09:00'
    );

    expect(app(PullQueueService::class)->queue()->first()->positionReason())->toBe('em risco: atrasada há 1 dia');
});

test('the age on the board is counted from the first board column, not from creation', function () {
    // Sat in the Caixa de Entrada for a month; it has only been *on the
    // board* since it was triaged into Backlog ten days ago.
    $activity = Activity::factory()->issue()->todo()->create([
        'created_at' => now()->subDays(30),
    ]);

    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => null,
        'to_status' => ActivityStatus::Inbox,
        'changed_at' => now()->subDays(30),
    ]);
    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => ActivityStatus::Inbox,
        'to_status' => ActivityStatus::Backlog,
        'changed_at' => now()->subDays(10),
    ]);
    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => now()->subDays(2),
    ]);

    $entry = app(PullQueueService::class)->queue()->first();

    expect($entry->ageInDays())->toBe(10);
    expect($entry->readyDays())->toBe(2);
});

test('an item triaged straight from Inbox into Pronto is one day old on the board, not thirty', function () {
    $activity = Activity::factory()->issue()->todo()->create([
        'created_at' => now()->subDays(30),
    ]);

    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => null,
        'to_status' => ActivityStatus::Inbox,
        'changed_at' => now()->subDays(30),
    ]);
    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => ActivityStatus::Inbox,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => now()->subDay(),
    ]);

    expect(app(PullQueueService::class)->queue()->first()->ageInDays())->toBe(1);
});

test('the age falls back to created_at when there is no board history', function () {
    $activity = Activity::factory()->issue()->todo()->create([
        'created_at' => now()->subDays(4),
    ]);
    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    expect(app(PullQueueService::class)->queue()->first()->ageInDays())->toBe(4);
});
