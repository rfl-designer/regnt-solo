<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;

/**
 * A guarda de apetite num browser de verdade (issue #152).
 *
 * A suíte Feature já prova a conta e o que cada superfície renderiza. O que
 * ela não prova é o caminho que a mão percorre: ver a aposta estourada na
 * página Fluxo, clicar em "Revisar escopo" e cair numa página de shaping que
 * edita o Épico — com um rodapé que não oferece promover nem adiar.
 *
 * @param  list<array{0: ActivityStatus, 1: string}>  $steps
 */
function withBetBrowserHistory(Activity $activity, array $steps): Activity
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
function browserBet(?int $appetiteDays, float $days, string $title): Activity
{
    $epic = Activity::factory()->epic()->create([
        'project_id' => Project::factory()->create()->id,
        'status' => ActivityStatus::Backlog,
        'appetite_days' => $appetiteDays,
        'title' => $title,
    ]);

    $approvedAt = now()->copy()->subDays($days);

    return withBetBrowserHistory($epic, [
        [ActivityStatus::AwaitingApproval, $approvedAt->copy()->subDay()->toDateTimeString()],
        [ActivityStatus::Doing, $approvedAt->toDateTimeString()],
    ]);
}

beforeEach(function (): void {
    BaselineCut::query()->delete();
    BaselineCut::factory()->create(['cut_at' => now()->copy()->subYear()]);
    $this->actingAs(User::factory()->create());
});

test('a página Fluxo mostra as apostas, com a estourada no topo e a sem apetite calada', function (): void {
    browserBet(appetiteDays: 30, days: 2, title: 'Aposta tranquila');
    browserBet(appetiteDays: 7, days: 12, title: 'Aposta estourada');
    browserBet(appetiteDays: null, days: 20, title: 'Aposta sem apetite');

    $page = visit('/flow')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-test="live-bets"]')
        ->assertSee('Apostas em andamento')
        // O excedente exato é assunto da suíte Feature, onde o relógio está
        // parado: aqui o servidor roda noutro processo e "há 12 dias" chega
        // com alguns microssegundos a mais.
        ->assertSeeIn('[data-test="live-bets"]', 'Apetite estourado')
        ->assertSeeIn('[data-test="live-bets"]', 'sem apetite definido');

    // Estourada no topo, sem apetite no fim — lido do DOM, que é onde a ordem
    // realmente aparece para quem olha a página.
    expect($page->script('(() => Array.from(document.querySelectorAll(\'[data-test="live-bets"] [wire\\\\:key^="bet-"] .truncate\')).map(el => el.textContent.trim()))()'))
        ->toBe(['Aposta estourada', 'Aposta tranquila', 'Aposta sem apetite']);

    // A guarda é silenciosa onde não há orçamento: a linha sem apetite existe,
    // mas não vem com barra nem com alarme.
    expect($page->script('document.querySelectorAll(\'[data-test="bet-no_appetite"] .bg-red-500, [data-test="bet-no_appetite"] .bg-amber-500, [data-test="bet-no_appetite"] .bg-emerald-500\').length'))
        ->toBe(0);
});

test('do estouro na página Fluxo até a revisão de escopo do Épico', function (): void {
    $epic = browserBet(appetiteDays: 7, days: 12, title: 'Aposta que precisa de corte');

    visit('/flow')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="bet-shaping-'.$epic->id.'"]')
        ->waitForText('Revisão de escopo')
        ->assertSee('Aposta que precisa de corte')
        ->assertSee('1 · Dor')
        ->assertSee('5 · No-gos')
        // O rodapé da revisão não promove nem adia.
        ->assertDontSee('Promover a Épico')
        ->assertDontSee('Talvez mais adiante')
        ->assertPresent('[data-test="back-to-board"]')
        ->assertNoJavaScriptErrors();
});

test('cortar um no-go na revisão escreve no próprio Épico', function (): void {
    $epic = browserBet(appetiteDays: 7, days: 12, title: 'Aposta em revisão');

    $page = visit(route('epic-shaping', $epic))->assertNoJavaScriptErrors();

    $page->fill('[data-test="field-no-gos"]', 'Sem exportacao nesta aposta.')
        ->click('[data-test="field-rabbit-holes"]')
        ->waitForText('Revisão de escopo');

    expect($epic->refresh()->no_gos)->toBe('Sem exportacao nesta aposta.');
});
