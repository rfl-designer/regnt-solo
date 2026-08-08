<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\MorningRitual;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * Put an item in Pronto with a known arrival time, so the pull queue's FIFO
 * is deterministic.
 */
function readyForRitual(Activity $activity, string $at): Activity
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

test('the ritual route renders the wizard and the old daily route redirects to it', function () {
    $this->get('/ritual')->assertOk()->assertSee('Ritual matinal');
    $this->get('/daily')->assertRedirect('/ritual');
});

// ── Passo 1 — Arquivar o Feito ───────────────────────────────────────────

test('step 1 lists what is done and archives one item in one click', function () {
    $done = Activity::factory()->issue()->done()->create(['title' => 'Fatia entregue']);
    Activity::factory()->issue()->doing()->create(['title' => 'Ainda fazendo']);

    Livewire::test('pages::morning-ritual')
        ->assertSee('Fatia entregue')
        ->assertDontSee('Ainda fazendo')
        ->call('archive', $done->id)
        ->assertDontSee('Fatia entregue');

    expect($done->fresh()->archived_at)->not->toBeNull()
        ->and($done->fresh()->status)->toBe(ActivityStatus::Done);
});

test('step 1 archives everything at once', function () {
    $items = Activity::factory()->issue()->done()->count(3)->create();

    Livewire::test('pages::morning-ritual')->call('archiveAll');

    expect($items->every(fn (Activity $a): bool => $a->fresh()->archived_at !== null))->toBeTrue();
});

// ── Passo 2 — Revisar esperas ────────────────────────────────────────────

test('step 2 shows the three waiting columns, oldest wait first', function () {
    $approval = Activity::factory()->issue()->awaitingApproval('Cliente A')->create(['title' => 'Esperando aprovação']);
    $internal = Activity::factory()->issue()->waiting('Designer')->create(['title' => 'Esperando interno']);
    $validation = Activity::factory()->issue()->awaitingValidation('Cliente B')->create(['title' => 'Esperando validação']);
    Activity::factory()->issue()->todo()->create(['title' => 'Nada a ver com espera']);

    $component = Livewire::test('pages::morning-ritual')->set('step', 2);

    $component->assertSee('Esperando aprovação')
        ->assertSee('Esperando interno')
        ->assertSee('Esperando validação')
        ->assertDontSee('Nada a ver com espera');

    expect($component->instance()->waitingItems->pluck('id')->all())
        ->toBe([$approval->id, $internal->id, $validation->id]);
});

test('step 2 resolves an approval forward to Pronto in one click', function () {
    $activity = Activity::factory()->issue()->awaitingApproval('Cliente')->create();

    Livewire::test('pages::morning-ritual')->set('step', 2)->call('resolveWait', $activity->id);

    expect($activity->fresh()->status)->toBe(ActivityStatus::Todo)
        ->and($activity->fresh()->waiting_for)->toBeNull();
});

test('step 2 resolves an internal wait to Pronto, never straight to Fazendo', function () {
    $activity = Activity::factory()->issue()->waiting('Designer')->create();

    Livewire::test('pages::morning-ritual')->set('step', 2)->call('resolveWait', $activity->id);

    expect($activity->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('step 2 resolves a validation to Feito', function () {
    $activity = Activity::factory()->issue()->awaitingValidation('Cliente')->create();

    Livewire::test('pages::morning-ritual')->set('step', 2)->call('resolveWait', $activity->id);

    expect($activity->fresh()->status)->toBe(ActivityStatus::Done)
        ->and($activity->fresh()->completed_at)->not->toBeNull();
});

// ── Passo 3 — Confirmar o Fazendo ────────────────────────────────────────

test('step 3 shows only Fazendo and sends an item back to Pronto in one click', function () {
    $doing = Activity::factory()->issue()->doing()->create(['title' => 'Em andamento']);
    Activity::factory()->issue()->todo()->create(['title' => 'Só pronta']);

    Livewire::test('pages::morning-ritual')
        ->set('step', 3)
        ->assertSee('Em andamento')
        ->assertDontSee('Só pronta')
        ->call('sendBackToReady', $doing->id);

    expect($doing->fresh()->status)->toBe(ActivityStatus::Todo);
});

// ── Passo 4 — Ver envelhecimento ─────────────────────────────────────────

test('step 4 lists by age with "amostra pequena (n=X)" and no alarm when there is no usable baseline', function () {
    config(['soloboard.sle_minimum_sample' => 30]);

    $old = Activity::factory()->issue()->doing()->create(['title' => 'Item antigo']);
    withSpecHistory($old, [[ActivityStatus::Todo, now()->subDays(40)->toDateTimeString()]]);

    $recent = Activity::factory()->issue()->todo()->create(['title' => 'Item recente']);
    withSpecHistory($recent, [[ActivityStatus::Todo, now()->subDay()->toDateTimeString()]]);

    $component = Livewire::test('pages::morning-ritual')->set('step', 4);

    $component->assertSee('Sem baseline utilizável')
        ->assertSee('amostra pequena (n=0)')
        ->assertSee('Lista por idade, sem alarme')
        ->assertSeeHtmlInOrder(['Item antigo', 'Item recente']);

    expect($component->instance()->agingRows->pluck('level')->unique()->all())->toBe(['ok']);
});

test('step 4 flags what is past the attention threshold once the baseline is usable', function () {
    config(['soloboard.sle_minimum_sample' => 3]);

    // Baseline: quatro itens com ciclo de 2 dias, concluídos depois do
    // corte que a migração semeia (é dali que a amostra conta).
    $finishedAt = now()->addMinute();

    foreach (range(1, 4) as $index) {
        $done = Activity::factory()->issue()->done()->create();
        withSpecHistory($done, [
            [ActivityStatus::Todo, $finishedAt->copy()->subDays(2)->toDateTimeString()],
            [ActivityStatus::Done, $finishedAt->toDateTimeString()],
        ]);
    }

    $aging = Activity::factory()->issue()->doing()->create(['title' => 'Queimando a SLE']);
    withSpecHistory($aging, [[ActivityStatus::Todo, now()->subDays(10)->toDateTimeString()]]);

    $component = Livewire::test('pages::morning-ritual')->set('step', 4);

    $component->assertSee('Queimando a SLE')->assertDontSee('amostra pequena');

    expect($component->instance()->agingRows->pluck('level')->all())->toContain('breach');
});

// ── Passo 5 — Puxar até encher o WIP ─────────────────────────────────────

test('step 5 shows the pull queue in order and pulls any card in one click', function () {
    config(['soloboard.wip_limit_doing' => 2]);

    $first = readyForRitual(Activity::factory()->issue()->todo()->create(['title' => 'Primeira da fila']), '2026-08-01 09:00');
    $second = readyForRitual(Activity::factory()->issue()->todo()->create(['title' => 'Segunda da fila']), '2026-08-02 09:00');

    $component = Livewire::test('pages::morning-ritual')->set('step', 5);

    $component->assertSeeHtmlInOrder(['Primeira da fila', 'Segunda da fila'])
        ->assertSee('0/2 em Fazendo')
        // Qualquer card pode ser puxado — inclusive o segundo.
        ->call('pullItem', $second->id)
        ->assertSee('1/2 em Fazendo');

    expect($second->fresh()->status)->toBe(ActivityStatus::Doing)
        ->and($first->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('step 5 refuses to pull past the WIP limit', function () {
    config(['soloboard.wip_limit_doing' => 1]);

    Activity::factory()->issue()->doing()->create();
    $queued = readyForRitual(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');

    Livewire::test('pages::morning-ritual')->set('step', 5)->call('pullItem', $queued->id);

    expect($queued->fresh()->status)->toBe(ActivityStatus::Todo);
});

// ── Tela final — o registro ──────────────────────────────────────────────

test('concluding the last screen records the ritual with optional notes', function () {
    $this->travelTo('2026-08-07 11:15:00'); // 08:15 local

    Livewire::test('pages::morning-ritual')
        ->set('step', 6)
        ->set('notes', 'Puxei a fatia de billing.')
        ->call('completeRitual')
        ->assertDispatched('ritual-completed');

    $ritual = MorningRitual::today();

    expect($ritual->isCompleted())->toBeTrue()
        ->and($ritual->completedAtLabel())->toBe('08:15')
        ->and($ritual->notes)->toBe('Puxei a fatia de billing.');
});

test('the ritual can be concluded without notes', function () {
    Livewire::test('pages::morning-ritual')->set('step', 6)->call('completeRitual');

    expect(MorningRitual::completedToday())->toBeTrue()
        ->and(MorningRitual::today()->notes)->toBeNull();
});

test('reopening on the same day says "já concluído às HH:MM"', function () {
    $this->travelTo('2026-08-07 11:15:00'); // 08:15 local
    Livewire::test('pages::morning-ritual')->set('step', 6)->call('completeRitual');

    $this->travelTo('2026-08-07 18:00:00'); // 15:00 local, mesmo dia

    Livewire::test('pages::morning-ritual')
        ->assertSee('Já concluído às 08:15')
        ->set('step', 6)
        ->call('completeRitual');

    expect(MorningRitual::today()->completedAtLabel())->toBe('08:15');
});

test('the wizard walks the five steps plus the record screen', function () {
    $component = Livewire::test('pages::morning-ritual')->assertSet('step', 1);

    foreach ([2, 3, 4, 5, 6] as $expected) {
        $component->call('nextStep')->assertSet('step', $expected);
    }

    $component->call('nextStep')->assertSet('step', 6)
        ->call('previousStep')->assertSet('step', 5)
        ->call('goToStep', 1)->assertSet('step', 1)
        ->call('previousStep')->assertSet('step', 1);
});

// ── Badge da sidebar ─────────────────────────────────────────────────────

test('the sidebar badge shows only while today\'s ritual is unfinished', function () {
    Livewire::test('ritual-badge')->assertSee('hoje');

    MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete(null);

    Livewire::test('ritual-badge')->assertDontSee('hoje');
});

test('a ritual concluded yesterday does not silence today\'s badge', function () {
    // Ancorado no tempo: "ontem" é ontem no fuso de negócio, e sem fixar o
    // relógio o teste passaria ou falharia conforme a hora em que roda.
    $this->travelTo('2026-08-07 14:00:00'); // 11:00 local

    MorningRitual::factory()->create([
        'date' => MorningRitual::businessToday()->subDay()->toDateString(),
        'completed_at' => now()->subDay(),
    ]);

    Livewire::test('ritual-badge')->assertSee('hoje');
});
