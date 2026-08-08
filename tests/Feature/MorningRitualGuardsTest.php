<?php

use App\Enums\ActivityStatus;
use App\Exceptions\ArchiveRequiresConcludedItemException;
use App\Models\Activity;
use App\Models\MorningRitual;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['soloboard.timezone' => 'America/Recife']);
});

/**
 * As invariantes que o ritual não pode perder (issue #147, review): o que
 * pode ser arquivado, o que acontece ao reabrir, e o que uma página aberta
 * durante a virada do dia tem o direito de gravar.
 */

// ── Arquivar só o que está concluído ─────────────────────────────────────

test('archive refuses anything that is not a concluded board item', function (string $state) {
    $activity = Activity::factory()->issue()->{$state}()->create();

    expect(fn () => $activity->archive())
        ->toThrow(ArchiveRequiresConcludedItemException::class);

    expect($activity->fresh()->archived_at)->toBeNull();
})->with(['todo', 'doing', 'backlog']);

test('archive refuses a draft and an Épico container', function () {
    $draft = Activity::factory()->draft()->create();

    $container = Activity::factory()->epic()->create(['status' => ActivityStatus::Done]);
    Activity::factory()->issue()->done()->create(['parent_id' => $container->id]);

    expect(fn () => $draft->archive())->toThrow(ArchiveRequiresConcludedItemException::class)
        ->and(fn () => $container->fresh()->archive())->toThrow(ArchiveRequiresConcludedItemException::class);
});

test('the ritual action ignores ids outside the step scope', function () {
    $inProgress = Activity::factory()->issue()->doing()->create(['title' => 'Trabalho vivo']);
    $ready = Activity::factory()->issue()->todo()->create();
    $draft = Activity::factory()->draft()->create();

    $component = Livewire::test('pages::morning-ritual');

    foreach ([$inProgress, $ready, $draft] as $activity) {
        $component->call('archive', $activity->id);
    }

    expect($inProgress->fresh()->archived_at)->toBeNull()
        ->and($inProgress->fresh()->status)->toBe(ActivityStatus::Doing)
        ->and($ready->fresh()->archived_at)->toBeNull()
        ->and($draft->fresh()->archived_at)->toBeNull();
});

test('an atomic Épico in Feito can be archived by the ritual', function () {
    $atomic = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Done,
        'title' => 'Épico atômico entregue',
    ]);

    Livewire::test('pages::morning-ritual')
        ->assertSee('Épico atômico entregue')
        ->call('archive', $atomic->id);

    expect($atomic->fresh()->archived_at)->not->toBeNull()
        ->and($atomic->fresh()->status)->toBe(ActivityStatus::Done);
});

// ── Reabrir desarquiva ───────────────────────────────────────────────────

test('reopening an archived item clears the archive stamp', function () {
    $activity = Activity::factory()->issue()->done()->create();
    $activity->archive();

    expect($activity->fresh()->archived_at)->not->toBeNull();

    $activity->fresh()->update(['status' => ActivityStatus::Doing]);

    expect($activity->fresh()->archived_at)->toBeNull();
});

test('an item archived, reopened and finished again is reviewable by the ritual', function () {
    $activity = Activity::factory()->issue()->done()->create(['title' => 'Voltou e concluiu de novo']);
    $activity->archive();

    Livewire::test('pages::morning-ritual')->assertDontSee('Voltou e concluiu de novo');

    $activity->fresh()->update(['status' => ActivityStatus::Doing]);
    $activity->fresh()->markAsDone();

    Livewire::test('pages::morning-ritual')->assertSee('Voltou e concluiu de novo');

    expect($activity->fresh()->archived_at)->toBeNull()
        ->and(Activity::query()->notArchived()->where('id', $activity->id)->exists())->toBeTrue();
});

test('un-archiving on reopen writes no extra status history', function () {
    $activity = Activity::factory()->issue()->done()->create();
    $activity->archive();
    $before = $activity->statusChanges()->count();

    $activity->fresh()->update(['status' => ActivityStatus::Doing]);

    expect($activity->fresh()->statusChanges()->count())->toBe($before + 1);
});

// ── Virada do dia com a página aberta ────────────────────────────────────

test('crossing midnight restarts the wizard instead of writing yesterday notes onto today', function () {
    $this->travelTo('2026-08-08 02:50:00'); // 23:50 local do dia 07

    $component = Livewire::test('pages::morning-ritual')
        ->assertSet('day', '2026-08-07')
        ->set('step', 6)
        ->set('notes', 'notas de ontem');

    $this->travelTo('2026-08-08 03:10:00'); // 00:10 local do dia 08

    $component->call('completeRitual')
        ->assertSet('day', '2026-08-08')
        ->assertSet('step', 1)
        ->assertSet('notes', '');

    // Nada foi gravado: nem o dia de ontem (que ninguém concluiu) nem o de
    // hoje (cujo ritual mal começou).
    expect(MorningRitual::query()->count())->toBe(0)
        ->and(MorningRitual::completedToday())->toBeFalse();
});

test('a mutation after midnight is abandoned and the day is resynced', function () {
    $this->travelTo('2026-08-08 02:50:00');

    $done = Activity::factory()->issue()->done()->create();
    $component = Livewire::test('pages::morning-ritual')->assertSet('day', '2026-08-07');

    $this->travelTo('2026-08-08 03:10:00');

    $component->call('archive', $done->id)->assertSet('day', '2026-08-08');

    expect($done->fresh()->archived_at)->toBeNull();
});

test('notes of a ritual concluded earlier the same day survive a re-render', function () {
    $this->travelTo('2026-08-07 11:15:00');

    Livewire::test('pages::morning-ritual')
        ->set('step', 6)
        ->set('notes', 'primeira passada')
        ->call('completeRitual');

    $this->travelTo('2026-08-07 18:00:00');

    Livewire::test('pages::morning-ritual')
        ->assertSet('day', '2026-08-07')
        ->assertSet('notes', 'primeira passada')
        ->assertSee('Já concluído às 08:15');
});
