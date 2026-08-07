<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * The Fluxo page (issue #145): the SLE, the distribution behind it, the
 * cut history that scopes it, and what is currently aging past it.
 */

/**
 * Fill the baseline with $count concluded items of $days cycle time each.
 */
function flowPageBaseline(int $count, int $days): void
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
 * An unfinished activity whose clock started $days ago.
 */
function agingActivity(ActivityStatus $status, float $days, string $title = 'Envelhecendo'): Activity
{
    $factory = match ($status) {
        ActivityStatus::Todo => Activity::factory()->issue()->todo(),
        ActivityStatus::Doing => Activity::factory()->issue()->doing(),
        ActivityStatus::Waiting => Activity::factory()->issue()->waiting(),
        default => Activity::factory()->issue()->awaitingValidation(),
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
    BaselineCut::factory()->create([
        'reason' => 'adoção do Fluxo Solo',
        'cut_at' => Carbon::parse('2026-01-01 00:00'),
    ]);

    config()->set('soloboard.sle_minimum_sample', 30);
    config()->set('soloboard.sle_percentile', 85);
    config()->set('soloboard.sle_attention_percent', 80);

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the page requires auth', function () {
    auth()->logout();

    $this->get('/flow')->assertRedirect('/login');
});

test('the page renders end to end, chart included', function () {
    flowPageBaseline(30, days: 10);

    $this->get('/flow')->assertOk()->assertSee('Fluxo');
});

test('the page shows the SLE once the baseline is big enough', function () {
    flowPageBaseline(30, days: 10);

    Livewire::test('pages::flow')
        ->assertSee('10 dias')
        ->assertSee('85%');
});

test('the page says "amostra pequena (n=X)" while the baseline is too small', function () {
    flowPageBaseline(4, days: 10);

    Livewire::test('pages::flow')
        ->assertSee('amostra pequena')
        ->assertSee('n=4');
});

test('the cut history is listed with the adoption cut from the migration', function () {
    Livewire::test('pages::flow')
        ->assertSee('adoção do Fluxo Solo')
        ->assertSee('Atual');
});

test('a manual cut requires a motivo', function () {
    Livewire::test('pages::flow')
        ->call('openCutModal')
        ->set('cutReason', '')
        ->call('saveCut')
        ->assertHasErrors(['cutReason' => 'required']);

    expect(BaselineCut::query()->count())->toBe(1);
});

test('a manual cut is recorded with its motivo and becomes the current one', function () {
    Livewire::test('pages::flow')
        ->call('openCutModal')
        ->set('cutReason', 'Cliente grande saiu')
        ->call('saveCut')
        ->assertHasNoErrors()
        ->assertSet('showCutModal', false)
        ->assertSee('Cliente grande saiu');

    expect(BaselineCut::query()->latestFirst()->first()->reason)->toBe('Cliente grande saiu');
});

test('a cut resets the population, so the page falls back to the small-sample message', function () {
    flowPageBaseline(30, days: 10);

    Livewire::test('pages::flow')
        ->assertSee('10 dias')
        ->call('openCutModal')
        ->set('cutReason', 'Mudei o jeito de trabalhar')
        ->call('saveCut')
        ->assertSee('amostra pequena')
        ->assertSee('n=0');
});

test('the aging list shows items past the attention threshold, worst first', function () {
    flowPageBaseline(30, days: 10);

    agingActivity(ActivityStatus::Todo, days: 9, title: 'Quase estourando');
    agingActivity(ActivityStatus::Doing, days: 15, title: 'Estourada');
    agingActivity(ActivityStatus::Waiting, days: 2, title: 'Tranquila');

    Livewire::test('pages::flow')
        ->assertSee('Estourada')
        ->assertSee('Quase estourando')
        ->assertDontSee('Tranquila')
        ->assertSeeInOrder(['Estourada', 'Quase estourando']);
});

test('without a usable baseline the page raises no alarm at all', function () {
    agingActivity(ActivityStatus::Doing, days: 300, title: 'Antiquíssima');

    Livewire::test('pages::flow')
        ->assertDontSee('Antiquíssima')
        ->assertSee('Sem baseline utilizável não há alarme');
});
