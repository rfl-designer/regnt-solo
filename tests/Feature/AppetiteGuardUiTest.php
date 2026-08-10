<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\User;
use App\Services\FlowMetricsService;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * Onde a guarda de apetite aparece (issue #152): a barra e o banner no modal
 * do Épico, e a seção "Apostas em andamento" na página Fluxo.
 *
 * Nenhuma das duas calcula nada — as duas leem
 * {@see FlowMetricsService::appetiteConsumption()}.
 *
 * @param  list<array{0: ActivityStatus, 1: string}>  $steps
 */
function withBetUiHistory(Activity $activity, array $steps): Activity
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

    return $activity->refresh();
}

/**
 * Uma aposta viva: aprovada há $days dias, ainda sem validação.
 */
function uiBet(?int $appetiteDays, float $days, array $attributes = []): Activity
{
    $epic = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Backlog,
        'appetite_days' => $appetiteDays,
        ...$attributes,
    ]);

    $approvedAt = Carbon::now()->copy()->subDays($days);

    return withBetUiHistory($epic, [
        [ActivityStatus::AwaitingApproval, $approvedAt->copy()->subDay()->toDateTimeString()],
        [ActivityStatus::Doing, $approvedAt->toDateTimeString()],
    ]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 12:00');
    BaselineCut::query()->delete();
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-01-01 00:00')]);
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    Carbon::setTestNow();
});

test('o modal do Épico mostra a barra de consumo junto da timeline', function () {
    $epic = uiBet(appetiteDays: 14, days: 9);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->assertSee('Apetite consumido')
        ->assertSee('9 de 14 dias')
        ->assertDontSee('Apetite estourado');
});

test('o modal avisa o estouro com o excedente e o atalho para o shaping', function () {
    $epic = uiBet(appetiteDays: 7, days: 10);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->assertSee('Apetite estourado (+3d)')
        ->assertSee('corte escopo ou mate a aposta')
        ->assertSee('Revisar escopo')
        ->assertSee(route('epic-shaping', $epic->id));
});

test('sem apetite o modal não mostra barra nem alarme', function () {
    $epic = uiBet(appetiteDays: null, days: 40);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->assertSee('sem apetite definido')
        ->assertDontSee('Apetite estourado')
        ->assertDontSee('Apetite consumido');
});

test('um Épico sem aprovação não fala de apetite nenhum', function () {
    $epic = Activity::factory()->epic()->create(['appetite_days' => 7]);

    withBetUiHistory($epic, [[ActivityStatus::Backlog, '2026-08-01 12:00']]);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->assertDontSee('Apetite consumido')
        ->assertDontSee('sem apetite definido');
});

test('a página Fluxo lista as apostas em andamento com as estouradas no topo', function () {
    uiBet(appetiteDays: 30, days: 2, attributes: ['title' => 'Aposta tranquila']);
    uiBet(appetiteDays: 7, days: 12, attributes: ['title' => 'Aposta estourada']);
    uiBet(appetiteDays: null, days: 20, attributes: ['title' => 'Aposta sem apetite']);

    $rendered = Livewire::test('pages::flow')
        ->assertSuccessful()
        ->assertSee('Apostas em andamento')
        ->assertSee('Aposta estourada')
        ->assertSee('Aposta tranquila')
        ->assertSee('Aposta sem apetite')
        ->assertSee('sem apetite definido')
        ->assertSee('Apetite estourado (+5d)')
        ->html();

    expect(strpos($rendered, 'Aposta estourada'))
        ->toBeLessThan(strpos($rendered, 'Aposta tranquila'))
        ->and(strpos($rendered, 'Aposta tranquila'))
        ->toBeLessThan(strpos($rendered, 'Aposta sem apetite'));
});

test('a página Fluxo deixa de fora o que ainda não é aposta e o que já foi validado', function () {
    $pending = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Backlog,
        'appetite_days' => 7,
        'title' => 'Esperando o sim do cliente',
    ]);
    withBetUiHistory($pending, [[ActivityStatus::AwaitingApproval, '2026-08-01 12:00']]);

    $validated = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Done,
        'appetite_days' => 7,
        'title' => 'Aposta encerrada',
    ]);
    withBetUiHistory($validated, [
        [ActivityStatus::AwaitingApproval, '2026-08-01 12:00'],
        [ActivityStatus::Doing, '2026-08-02 12:00'],
        [ActivityStatus::Done, '2026-08-05 12:00'],
    ]);

    Livewire::test('pages::flow')
        ->assertSee('Nenhuma aposta em andamento')
        ->assertDontSee('Esperando o sim do cliente')
        ->assertDontSee('Aposta encerrada');
});
