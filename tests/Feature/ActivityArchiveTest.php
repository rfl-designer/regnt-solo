<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\User;
use App\Services\FlowMetricsService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * Arquivar é um carimbo, não um status (issue #147). O que este arquivo
 * protege é exatamente isso: o item some da coluna Feito sem que nada nas
 * métricas de fluxo mude.
 */
test('archiving sets the timestamp without touching the status', function () {
    $activity = Activity::factory()->issue()->done()->create();

    $activity->archive();

    expect($activity->fresh()->archived_at)->not->toBeNull()
        ->and($activity->fresh()->status)->toBe(ActivityStatus::Done)
        ->and($activity->fresh()->isArchived())->toBeTrue();
});

test('archiving twice keeps the first timestamp', function () {
    $this->travelTo('2026-08-07 09:00:00');
    $activity = Activity::factory()->issue()->done()->create();
    $activity->archive();
    $first = $activity->fresh()->archived_at;

    $this->travelTo('2026-08-08 09:00:00');
    $activity->fresh()->archive();

    expect($activity->fresh()->archived_at->toDateTimeString())->toBe($first->toDateTimeString());
});

test('archiving records no status change at all', function () {
    $activity = Activity::factory()->issue()->done()->create();
    $before = $activity->statusChanges()->count();

    $activity->archive();

    expect($activity->fresh()->statusChanges()->count())->toBe($before);
});

test('unarchiving brings the item back', function () {
    $activity = Activity::factory()->issue()->done()->create();
    $activity->archive();

    $activity->fresh()->unarchive();

    expect($activity->fresh()->archived_at)->toBeNull();
});

test('the archived and notArchived scopes split the board', function () {
    $archived = Activity::factory()->issue()->done()->create();
    $archived->archive();
    $open = Activity::factory()->issue()->done()->create();

    expect(Activity::query()->archived()->pluck('id')->all())->toBe([$archived->id])
        ->and(Activity::query()->notArchived()->pluck('id')->all())->toBe([$open->id]);
});

test('the cycle time and the SLE do not move when an item is archived', function () {
    config(['soloboard.sle_minimum_sample' => 3]);

    // Concluídos *depois* do corte de baseline que a migração semeia — é
    // essa janela que o SLE mede.
    $finishedAt = now()->addMinute();

    $items = collect(range(1, 4))->map(function (int $index) use ($finishedAt): Activity {
        $activity = Activity::factory()->issue()->done()->create();

        withSpecHistory($activity, [
            [ActivityStatus::Todo, $finishedAt->copy()->subDays($index)->toDateTimeString()],
            [ActivityStatus::Done, $finishedAt->toDateTimeString()],
        ]);

        return $activity->fresh();
    });

    $before = app(FlowMetricsService::class);
    $sampleBefore = $before->sample();
    $sleBefore = $before->sleDays();
    $cycleBefore = $before->cycleTimeDays($items->first());

    $items->each(fn (Activity $activity) => $activity->archive());

    $after = app(FlowMetricsService::class);

    expect($after->sample())->toBe($sampleBefore)
        ->and($after->sleDays())->toBe($sleBefore)
        ->and($after->cycleTimeDays($items->first()->fresh()))->toBe($cycleBefore)
        ->and($sleBefore)->not->toBeNull();
});

test('the Feito column shows only what has not been archived', function () {
    $visible = Activity::factory()->issue()->done()->create(['title' => 'Feito ainda não arquivado']);
    $archived = Activity::factory()->issue()->done()->create(['title' => 'Feito já arquivado']);
    $archived->archive();

    Livewire::test('pages::kanban')
        ->assertSee('Feito ainda não arquivado')
        ->assertDontSee('Feito já arquivado');

    expect($visible->fresh()->status)->toBe(ActivityStatus::Done);
});

test('the Feito column no longer hides items by the calendar week', function () {
    // Concluída há um mês: o filtro de semana a esconderia; o arquivamento
    // (que ninguém fez) é o que passa a decidir.
    Activity::factory()->issue()->done()->create([
        'title' => 'Concluída no mês passado',
        'completed_at' => now()->subMonth(),
    ]);

    Livewire::test('pages::kanban')->assertSee('Concluída no mês passado');
});

test('the archived filter reaches every archivable type, not just personal tasks', function () {
    // O ritual arquiva qualquer folha do quadro. Se o filtro só mostrasse
    // type=Task, uma Issue arquivada sumiria da coluna Feito sem existir em
    // nenhuma outra superfície (issue #147, review).
    $issue = Activity::factory()->issue()->done()->create(['title' => 'Fatia arquivada']);
    $atomicEpic = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Done,
        'title' => 'Épico atômico arquivado',
    ]);
    $task = Activity::factory()->task()->done()->create(['title' => 'Recado arquivado']);

    foreach ([$issue, $atomicEpic, $task] as $activity) {
        $activity->archive();
    }

    Livewire::test('pages::tasks')
        ->set('showArchived', true)
        ->assertSee('Fatia arquivada')
        ->assertSee('Épico atômico arquivado')
        ->assertSee('Recado arquivado');
});

test('the live view stays personal — archived issues never leak into it', function () {
    $issue = Activity::factory()->issue()->done()->create(['title' => 'Fatia qualquer']);

    Livewire::test('pages::tasks')
        ->assertDontSee('Fatia qualquer')
        ->set('showArchived', true)
        ->assertDontSee('Fatia qualquer');

    expect($issue->fresh()->isArchived())->toBeFalse();
});

test('the tasks page hides archived tasks until the filter is on', function () {
    $open = Activity::factory()->task()->done()->create(['title' => 'Recado aberto']);
    $archived = Activity::factory()->task()->done()->create(['title' => 'Recado arquivado']);
    $archived->archive();

    Livewire::test('pages::tasks')
        ->assertSee('Recado aberto')
        ->assertDontSee('Recado arquivado')
        ->set('showArchived', true)
        ->assertSee('Recado arquivado')
        ->assertDontSee('Recado aberto');

    expect($open->fresh()->isArchived())->toBeFalse();
});
