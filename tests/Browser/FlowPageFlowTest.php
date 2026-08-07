<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\User;

/**
 * The Fluxo page in a real browser (issue #145): the SLE card, the
 * distribution, the cut history and the aging list; the cut modal's
 * validation; and, on the board, the chip that navigates here and the
 * borders the aging cards wear.
 *
 * The clock is deliberately left alone here — `Carbon::setTestNow()` does
 * not reach the process serving the page — so every fixture is built from
 * the real `now()`, and the SLE thresholds come from the shipped config
 * (85th percentile, 30 items, 80% attention) rather than from `config()->set`.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    BaselineCut::query()->delete();
    BaselineCut::factory()->create([
        'reason' => 'adoção do Fluxo Solo',
        'cut_at' => now()->copy()->subYear(),
    ]);
});

/**
 * Fill the baseline with $count concluded items of $days cycle time each,
 * so the percentile has a population and lands exactly on $days.
 */
function browserBaseline(int $count, int $days): void
{
    $finishedAt = now()->copy()->subHour();

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
function browserCard(ActivityStatus $status, float $days, string $title): Activity
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

/**
 * The classes on the rendered card carrying $title, read off the DOM: the
 * border colour is the alarm, so it is asserted where the user sees it and
 * not from the component's return value.
 */
function cardBorderClassScript(string $title): string
{
    $needle = json_encode($title);

    return <<<JS
    (() => {
        const card = Array.from(document.querySelectorAll('li.kanban-card'))
            .find(item => item.textContent.includes({$needle}));

        if (! card) {
            return 'not-rendered';
        }

        const bordered = card.querySelector('[class*="border-"]');

        return bordered ? bordered.className.replace(/\s+/g, ' ').trim() : 'sem-borda';
    })()
    JS;
}

test('the Fluxo page renders the SLE, the distribution, the cut history and the aging list', function (): void {
    browserBaseline(30, days: 10);

    browserCard(ActivityStatus::Todo, days: 15, title: 'Estourada no fluxo');
    browserCard(ActivityStatus::Waiting, days: 1, title: 'Tranquila no fluxo');

    visit('/flow')
        ->assertNoJavaScriptErrors()
        ->assertNoBrokenImages()
        ->assertSee('Fluxo')
        // The SLE card, quoting the measured promise.
        ->assertSee('10 dias')
        ->assertSee('85%')
        ->assertSee('30 itens concluídos')
        // The distribution behind it — the chart really mounted.
        ->assertSee('Distribuição dos cycle times')
        ->assertScript('document.querySelector(\'[data-test="distribution"] svg\') !== null', true)
        // The cut that scopes the measurement.
        ->assertSee('Cortes de baseline')
        ->assertSee('adoção do Fluxo Solo')
        ->assertSee('Atual')
        // And what is currently breaking the promise.
        ->assertSee('Envelhecendo')
        ->assertSee('Estourada no fluxo')
        ->assertDontSee('Tranquila no fluxo');
});

test('without a usable baseline the page admits the small sample and raises no alarm', function (): void {
    browserBaseline(4, days: 10);

    browserCard(ActivityStatus::Doing, days: 300, title: 'Antiquíssima no fluxo');

    visit('/flow')
        ->assertNoJavaScriptErrors()
        ->assertSee('amostra pequena')
        ->assertSee('n=4')
        ->assertSee('Sem baseline utilizável não há alarme')
        ->assertDontSee('Antiquíssima no fluxo');
});

test('the cut modal refuses a blank motivo and records the cut once it is given', function (): void {
    $page = visit('/flow')
        ->assertNoJavaScriptErrors()
        ->assertDontSee('Cliente grande saiu');

    // Clicked by their data-test hooks rather than by their labels: the
    // modal's "Cortar" and the header's "Cortar baseline" overlap as text,
    // and an ambiguous match would wait on nothing forever.
    $page->click('[data-test="cut-open"]')
        ->waitForText('Motivo')
        // Submitted empty: the field-level message is what the user gets.
        ->click('[data-test="cut-submit"]')
        ->waitForText('Descreva o motivo do corte.')
        ->assertNoJavaScriptErrors();

    expect(BaselineCut::query()->count())->toBe(1);

    $page->fill('[data-test="cut-reason"]', 'Cliente grande saiu')
        ->click('[data-test="cut-submit"]')
        ->waitForText('Baseline cortada')
        ->assertNoJavaScriptErrors()
        // The new cut is in the history, and it is the one that counts.
        ->assertSee('Cliente grande saiu')
        ->assertSee('Atual');

    expect(BaselineCut::query()->count())->toBe(2)
        ->and(BaselineCut::query()->latestFirst()->first()->reason)->toBe('Cliente grande saiu');
});

test('the chip in the Kanban header quotes the SLE and navigates to Fluxo', function (): void {
    browserBaseline(30, days: 10);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('SLE 85% ≤ 10d')
        ->click('[data-test="sle-chip"]')
        ->waitForText('Distribuição dos cycle times')
        ->assertNoJavaScriptErrors()
        ->assertUrlIs(url('/flow'))
        ->assertSee('O que o quadro promete');
});

test('the chip admits a small sample and still leads to Fluxo', function (): void {
    browserBaseline(5, days: 10);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('amostra pequena (n=5)')
        ->click('[data-test="sle-chip"]')
        ->waitForText('Cortes de baseline')
        ->assertNoJavaScriptErrors()
        ->assertUrlIs(url('/flow'));
});

test('a card past the attention threshold wears an amber border and one past the SLE a red one', function (): void {
    browserBaseline(30, days: 10);

    browserCard(ActivityStatus::Todo, days: 8, title: 'Na atencao no board');
    browserCard(ActivityStatus::Waiting, days: 12, title: 'Estourada no board');
    browserCard(ActivityStatus::Doing, days: 2, title: 'Tranquila no board');

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Na atencao no board')
        ->assertSee('Estourada no board')
        ->assertSee('Tranquila no board');

    expect($page->script(cardBorderClassScript('Na atencao no board')))
        ->toContain('border-amber-500');

    expect($page->script(cardBorderClassScript('Estourada no board')))
        ->toContain('border-red-500');

    // Comfortably inside the SLE: the default border, no alarm at all.
    expect($page->script(cardBorderClassScript('Tranquila no board')))
        ->toContain('border-zinc-700')
        ->not->toContain('border-amber-500')
        ->not->toContain('border-red-500');
});

test('no card is flagged while the baseline is unusable, however old it is', function (): void {
    browserCard(ActivityStatus::Doing, days: 400, title: 'Fossil no board');

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Fossil no board')
        ->assertDontSee('dias da SLE');

    expect($page->script(cardBorderClassScript('Fossil no board')))
        ->toContain('border-zinc-700')
        ->not->toContain('border-amber-500')
        ->not->toContain('border-red-500');
});
