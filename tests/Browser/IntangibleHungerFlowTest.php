<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\User;

/**
 * A fome de Intangível em navegador de verdade (issue #153): o card sempre
 * visível da página Fluxo — cinza dentro do limiar, âmbar ao estourar — e o
 * banner sem dispensa do passo de puxar do ritual, tanto com Intangível em
 * Pronto quanto na despensa vazia da partida a frio.
 *
 * Como em {@see FlowPageFlowTest}, o relógio é deixado em paz —
 * `Carbon::setTestNow()` não alcança o processo que serve a página — então
 * cada fixture é montada a partir do `now()` real e o limiar vem do config
 * publicado (14 dias).
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    // A migration semeia o corte de adoção com `cut_at = now()`; cada cenário
    // declara o corte que quer medir.
    BaselineCut::query()->delete();
});

/**
 * Um corte de baseline há $days dias — a âncora da partida a frio.
 */
function hungerCut(int $days): BaselineCut
{
    return BaselineCut::factory()->create([
        'reason' => 'adoção do Fluxo Solo',
        // Uma hora de folga: o card arredonda a fome para cima ao estourar,
        // então a fixture fica longe da virada do dia inteiro.
        'cut_at' => now()->copy()->subDays($days)->addHour(),
    ]);
}

/**
 * Um Intangível concluído há $days dias — o que zera a fome.
 *
 * O card arredonda a fome para cima quando estoura e para baixo quando está
 * dentro do limiar, então a fixture estourada nasce uma hora aquém do dia
 * cheio: sem essa folga, os segundos que a página leva para renderizar
 * empurrariam o rótulo para $days + 1.
 */
function hungerConcluded(int $days, bool $starving = false): Activity
{
    $finishedAt = now()->copy()->subDays($days);

    if ($starving) {
        $finishedAt = $finishedAt->addHour();
    }

    $activity = Activity::factory()->issue()->intangible()->done()->create([
        'completed_at' => $finishedAt,
        'title' => 'Intangível concluído',
    ]);

    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => $finishedAt->copy()->subDays(2),
    ]);

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => ActivityStatus::Todo,
        'to_status' => ActivityStatus::Done,
        'changed_at' => $finishedAt,
    ]);

    return $activity;
}

/**
 * Um Intangível em Pronto, com chegada conhecida para a fila ser determinística.
 */
function hungerInPronto(string $title, int $daysAgo): Activity
{
    $activity = Activity::factory()->issue()->intangible()->todo()->create(['title' => $title]);

    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => now()->copy()->subDays($daysAgo),
    ]);

    return $activity;
}

/**
 * Se o card de Intangível veste a classe dada, lido do DOM: a borda âmbar é o
 * alarme, então é conferida onde o usuário a vê, não no retorno do componente.
 */
function intangibleCardHasClassScript(string $class): string
{
    $needle = json_encode($class);

    return <<<JS
    (() => {
        const card = document.querySelector('[data-test="intangible-card"]');

        return card ? card.className.split(/\s+/).includes({$needle}) : false;
    })()
    JS;
}

// ── Página Fluxo ─────────────────────────────────────────────────────────

test('the Fluxo page shows the Intangível card in grey while the hunger is fed', function (): void {
    hungerCut(days: 365);
    hungerConcluded(days: 3);

    visit('/flow')
        ->assertNoJavaScriptErrors()
        ->assertSee('Intangível')
        ->assertPresent('[data-test="intangible-card"]')
        ->assertPresent('[data-test="intangible-fed"]')
        ->assertMissing('[data-test="intangible-starving"]')
        ->assertSee('Dentro do limiar')
        ->assertSee('última conclusão há 3 dias')
        ->assertSee('limiar de 14 dias')
        ->assertScript(intangibleCardHasClassScript('border-zinc-700'), true)
        ->assertScript(intangibleCardHasClassScript('border-amber-500/40'), false);
});

test('the Intangível card turns amber in the browser once the threshold is crossed', function (): void {
    hungerCut(days: 365);
    hungerConcluded(days: 21, starving: true);
    hungerInPronto('Refatorar o observer', daysAgo: 9);

    visit('/flow')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-test="intangible-starving"]')
        ->assertMissing('[data-test="intangible-fed"]')
        ->assertSee('Fome de Intangível')
        ->assertSee('última conclusão há 21 dias')
        ->assertSee('limiar de 14 dias')
        // A despensa cheia troca o remédio para "conclua o que já está em Pronto".
        ->assertSee('concluir um é o que mata a fome')
        ->assertScript(intangibleCardHasClassScript('border-amber-500/40'), true);
});

test('the Intangível card is already amber on a cold start anchored on the cut', function (): void {
    hungerCut(days: 40);

    visit('/flow')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-test="intangible-card"]')
        ->assertPresent('[data-test="intangible-starving"]')
        ->assertSee('nenhuma conclusão desde o corte, há 40 dias')
        // Despensa vazia: o remédio é criar ou promover, não puxar.
        ->assertSee('o remédio é criar ou promover um, no Backlog ou nas Ideias')
        ->assertScript(intangibleCardHasClassScript('border-amber-500/40'), true);
});

// ── Ritual matinal, passo de puxar ───────────────────────────────────────

test('the ritual pull step shows no banner while the hunger is fed', function (): void {
    hungerCut(days: 365);
    hungerConcluded(days: 3);
    hungerInPronto('Proxima da fila', daysAgo: 9);

    visit('/ritual')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="step-tab-5"]')
        ->waitForText('0/2 em Fazendo')
        ->assertMissing('[data-test="ritual-intangible-banner"]')
        ->assertNoJavaScriptErrors();
});

test('the ritual banner fires with a Puxar shortcut and cannot be dismissed', function (): void {
    hungerCut(days: 40);
    $intangible = hungerInPronto('Refatorar o observer', daysAgo: 9);

    $page = visit('/ritual')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="step-tab-5"]')
        ->waitForText('0/2 em Fazendo')
        ->assertPresent('[data-test="ritual-intangible-banner"]')
        ->assertSee('Fome de Intangível: nenhuma conclusão desde o corte, há 40 dias')
        ->assertPresent('[data-test="ritual-intangible-shortcut"]')
        ->assertSee('Refatorar o observer')
        // Sem dispensa: o banner não oferece saída que não seja concluir.
        ->assertDontSee('Dispensar');

    // O atalho puxa de dentro do próprio ritual.
    $page->click('[data-test="ritual-intangible-shortcut"] button')
        ->waitForText('1/2 em Fazendo')
        ->assertNoJavaScriptErrors();

    expect($intangible->fresh()->status)->toBe(ActivityStatus::Doing);

    // Puxar não zera a fome — só concluir zera —, então o banner continua lá,
    // agora sem ninguém em Pronto para puxar.
    $page->assertPresent('[data-test="ritual-intangible-banner"]')
        ->assertMissing('[data-test="ritual-intangible-shortcut"]');
});

test('with nothing Intangível in Pronto the ritual banner points to Backlog and Ideias', function (): void {
    hungerCut(days: 40);

    // Uma Padrão em Pronto não serve de remédio: a despensa de Intangível
    // continua vazia.
    $standard = Activity::factory()->issue()->todo()->create(['title' => 'Padrao qualquer']);
    ActivityStatusChange::query()->where('activity_id', $standard->id)->delete();
    ActivityStatusChange::factory()->create([
        'activity_id' => $standard->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => now()->copy()->subDays(9),
    ]);

    visit('/ritual')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="step-tab-5"]')
        ->waitForText('0/2 em Fazendo')
        ->assertPresent('[data-test="ritual-intangible-banner"]')
        ->assertPresent('[data-test="ritual-intangible-empty"]')
        ->assertSee('E não há nenhum Intangível em Pronto.')
        ->assertPresent('[data-test="ritual-intangible-backlog"]')
        ->assertPresent('[data-test="ritual-intangible-ideas"]')
        ->assertMissing('[data-test="ritual-intangible-shortcut"]')
        ->assertNoJavaScriptErrors();
});
