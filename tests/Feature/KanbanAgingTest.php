<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * The SLE on the board (issue #145): the chip in the header, and the
 * borders the cards wear as they burn through the promise.
 */

/**
 * Fill the baseline with $count concluded items of $days cycle time each.
 */
function kanbanBaseline(int $count, int $days): void
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

/**
 * An unfinished card in $status whose clock started $days ago.
 */
function boardCard(ActivityStatus $status, float $days, string $title): Activity
{
    $factory = match ($status) {
        ActivityStatus::Todo => Activity::factory()->issue()->todo(),
        ActivityStatus::Doing => Activity::factory()->issue()->doing(),
        ActivityStatus::Waiting => Activity::factory()->issue()->waiting(),
        ActivityStatus::AwaitingValidation => Activity::factory()->issue()->awaitingValidation(),
        default => Activity::factory()->issue()->backlog(),
    };

    $activity = $factory->create(['title' => $title]);

    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => now()->copy()->subDays($days),
    ]);

    return $activity;
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00');
    BaselineCut::query()->delete();
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-01-01 00:00')]);

    config()->set('soloboard.sle_minimum_sample', 30);
    config()->set('soloboard.sle_percentile', 85);
    config()->set('soloboard.sle_attention_percent', 80);

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the header chip quotes the SLE and links to the Fluxo page', function () {
    kanbanBaseline(30, days: 10);

    Livewire::test('pages::kanban')
        ->assertSee('SLE 85% ≤ 10d')
        ->assertSee(route('flow'), escape: false);
});

test('the chip admits a small sample instead of quoting a number', function () {
    kanbanBaseline(5, days: 10);

    Livewire::test('pages::kanban')
        ->assertSee('amostra pequena (n=5)')
        ->assertSee(route('flow'), escape: false);
});

test('a card at 80% of the SLE turns amber, and one past 100% turns red', function () {
    kanbanBaseline(30, days: 10);

    boardCard(ActivityStatus::Doing, days: 8, title: 'Na atenção');
    boardCard(ActivityStatus::Waiting, days: 12, title: 'Estourada');

    Livewire::test('pages::kanban')
        ->assertSee('border-amber-500', escape: false)
        ->assertSee('border-red-500', escape: false)
        ->assertSee('8 de 10 dias da SLE')
        ->assertSee('12 de 10 dias da SLE');
});

test('a card comfortably inside the SLE wears no alarm border', function () {
    kanbanBaseline(30, days: 10);

    boardCard(ActivityStatus::Doing, days: 2, title: 'Tranquila');

    Livewire::test('pages::kanban')
        ->assertDontSee('border-amber-500', escape: false)
        ->assertDontSee('border-red-500', escape: false)
        ->assertDontSee('dias da SLE');
});

test('without a usable baseline no card is ever flagged, however old it is', function () {
    boardCard(ActivityStatus::Doing, days: 400, title: 'Fóssil');

    Livewire::test('pages::kanban')
        ->assertSee('Fóssil')
        ->assertDontSee('border-amber-500', escape: false)
        ->assertDontSee('border-red-500', escape: false)
        ->assertDontSee('dias da SLE');
});

test('aging reaches the four target columns, and only them', function (string $status, bool $flagged) {
    kanbanBaseline(30, days: 10);

    boardCard(ActivityStatus::from($status), days: 20, title: 'Alvo');

    $test = Livewire::test('pages::kanban');

    $flagged
        ? $test->assertSee('20 de 10 dias da SLE')
        : $test->assertDontSee('dias da SLE');
})->with([
    'Pronto' => ['todo', true],
    'Fazendo' => ['doing', true],
    'Esperando' => ['waiting', true],
    'Aguardando validação' => ['awaiting_validation', true],
    // Backlog is before the commitment: nothing has been promised, so
    // there is nothing to be late on.
    'Backlog' => ['backlog', false],
]);

test('the Pronto card keeps its motivo in the tooltip and adds the aging to it', function () {
    kanbanBaseline(30, days: 10);

    boardCard(ActivityStatus::Todo, days: 15, title: 'Pronta e velha');

    Livewire::test('pages::kanban')
        ->assertSee('15 de 10 dias da SLE')
        ->assertSee('border-red-500', escape: false);
});
