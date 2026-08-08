<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\MorningRitual;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * Put an item in Pronto with a known arrival, so the pull queue of step 5
 * has a FIFO the browser can assert instead of an accident of insert order.
 */
function ritualEnterPronto(Activity $activity, string $at): Activity
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

test('the morning ritual walks the five steps in the browser and records the day', function (): void {
    $done = Activity::factory()->issue()->done()->create(['title' => 'Fatia entregue ontem']);
    $waiting = Activity::factory()->issue()->waiting('Designer')->create(['title' => 'Travada no designer']);
    $doing = Activity::factory()->issue()->doing()->create(['title' => 'Nao e mais isto']);
    $toPull = ritualEnterPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Proxima da fila']),
        '2026-08-01 09:00'
    );

    // ── Antes do ritual: a sidebar cutuca ────────────────────────────────
    $page = visit('/ritual')
        ->assertNoJavaScriptErrors()
        ->assertSee('Ritual matinal')
        ->assertPresent('[data-test="ritual-pending-badge"]')
        ->assertDontSee('Já concluído às');

    // ── Passo 1 — Arquivar o Feito ───────────────────────────────────────
    $page->assertSee('Fatia entregue ontem')
        ->click('[data-test="archive-all"]')
        ->waitForText('Nada em Feito para arquivar.')
        ->assertNoJavaScriptErrors();

    // Arquivar é um carimbo: continua Feito, só sai da lista.
    expect($done->fresh()->archived_at)->not->toBeNull()
        ->and($done->fresh()->status)->toBe(ActivityStatus::Done);

    // ── Passo 2 — Revisar esperas ────────────────────────────────────────
    $page->click('[data-test="step-tab-2"]')
        ->waitForText('Travada no designer')
        ->click('Destravou')
        ->waitForText('Nada esperando.')
        ->assertNoJavaScriptErrors();

    // Destravar devolve para Pronto, nunca direto para Fazendo.
    expect($waiting->fresh()->status)->toBe(ActivityStatus::Todo);

    // ── Passo 3 — Confirmar o Fazendo ────────────────────────────────────
    $page->click('[data-test="step-tab-3"]')
        ->waitForText('Nao e mais isto')
        ->click('Devolver p/ Pronto')
        ->waitForText('Nada em Fazendo.')
        ->assertNoJavaScriptErrors();

    expect($doing->fresh()->status)->toBe(ActivityStatus::Todo);

    // ── Passo 4 — Ver envelhecimento ─────────────────────────────────────
    $page->click('[data-test="step-tab-4"]')
        ->waitForText('Ver envelhecimento')
        ->assertSee('Proxima da fila')
        ->assertPresent('[data-test="aging-caption"]')
        ->assertNoJavaScriptErrors();

    // ── Passo 5 — Puxar até encher o WIP ─────────────────────────────────
    $page->click('[data-test="step-tab-5"]')
        ->waitForText('0/2 em Fazendo')
        ->assertSee('Proxima da fila')
        ->click('Puxar')
        ->waitForText('1/2 em Fazendo')
        ->assertNoJavaScriptErrors();

    expect($toPull->fresh()->status)->toBe(ActivityStatus::Doing);

    // ── Registro do ritual ───────────────────────────────────────────────
    $page->click('[data-test="step-tab-6"]')
        ->waitForText('Fim do ritual. As notas são opcionais.')
        // O modal global de log tem outro textarea `notes`; este é o do ritual.
        ->fill('textarea[placeholder="O que ficou na cabeça hoje..."]', 'Dia comecou pela fila.')
        ->click('[data-test="complete-ritual"]')
        ->waitForText('Já concluído às')
        ->assertNoJavaScriptErrors();

    $ritual = MorningRitual::today();

    expect($ritual)->not->toBeNull()
        ->and($ritual->completed_at)->not->toBeNull()
        ->and($ritual->notes)->toBe('Dia comecou pela fila.');

    // O badge da sidebar some assim que o dia é registrado.
    $page->assertMissing('[data-test="ritual-pending-badge"]');

    // ── Reabrir: a primeira conclusão do dia é a que vale ────────────────
    visit('/ritual')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-test="ritual-already-completed"]')
        ->assertSee('Já concluído às '.$ritual->completedAtLabel())
        ->assertMissing('[data-test="ritual-pending-badge"]');
});
