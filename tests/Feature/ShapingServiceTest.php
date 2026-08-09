<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Exceptions\ShapingIncompleteException;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\Project;
use App\Services\FlowMetricsService;
use App\Services\ShapingService;
use Carbon\Carbon;

/**
 * Shaping's domain rules (issue #148): what counts as shaped, what the
 * promotion refuses, what the pitch says, and what the histórico aside is
 * allowed to claim.
 */

/**
 * An Épico whose Spec was approved and validated $days apart — one row of
 * the population the apetite aside compares against.
 */
function validatedBet(float $days, string $validatedAt = '2026-08-01 12:00'): Activity
{
    $epic = Activity::factory()->epic()->create(['status' => ActivityStatus::Done]);

    ActivityStatusChange::query()->where('activity_id', $epic->id)->delete();

    $end = Carbon::parse($validatedAt);
    $start = $end->copy()->subDays($days);

    ActivityStatusChange::factory()->create([
        'activity_id' => $epic->id,
        'from_status' => null,
        'to_status' => ActivityStatus::AwaitingApproval,
        'changed_at' => $start->copy()->subDay(),
    ]);

    ActivityStatusChange::factory()->create([
        'activity_id' => $epic->id,
        'from_status' => ActivityStatus::AwaitingApproval,
        'to_status' => ActivityStatus::Doing,
        'changed_at' => $start,
    ]);

    ActivityStatusChange::factory()->create([
        'activity_id' => $epic->id,
        'from_status' => ActivityStatus::Doing,
        'to_status' => ActivityStatus::Done,
        'changed_at' => $end,
    ]);

    return $epic;
}

function shaping(): ShapingService
{
    return app(ShapingService::class);
}

test('progress counts the five sections and nothing else', function () {
    $draft = Activity::factory()->draft()->create([
        'description' => null,
        'spec' => null,
    ]);

    expect(shaping()->progress($draft))->toBe(0)
        ->and(shaping()->isShaped($draft))->toBeFalse();

    $draft->update(['description' => 'Dói de verdade']);
    expect(shaping()->progress($draft->refresh()))->toBe(1)
        ->and(shaping()->isShaped($draft))->toBeTrue();

    $draft->update(['appetite_days' => 7, 'spec' => 'Esboço', 'rabbit_holes' => 'RH', 'no_gos' => 'NG']);
    expect(shaping()->progress($draft->refresh()))->toBe(5);
});

test('an editor opened and closed does not count as a filled section', function () {
    $draft = Activity::factory()->draft()->create([
        'description' => '<p></p>',
        'spec' => '<p>&nbsp;</p>',
    ]);

    expect(shaping()->progress($draft))->toBe(0)
        ->and(shaping()->isShaped($draft))->toBeFalse();
});

test('promotion refuses an unshaped idea and names everything missing', function () {
    $draft = Activity::factory()->draft()->create(['description' => null, 'spec' => null]);

    expect(shaping()->missingForPromotion($draft))->toBe(['Dor', 'Apetite', 'Esboço', 'Projeto']);

    expect(fn () => shaping()->promote($draft))
        ->toThrow(ShapingIncompleteException::class, 'Para promover a Ideia a Épico, falta: Dor, Apetite, Esboço e Projeto.');

    expect($draft->refresh()->type)->toBe(ActivityType::Draft);
});

test('promotion refuses a fully shaped idea with no project', function () {
    $draft = Activity::factory()->draft()->shaped()->create();

    expect(shaping()->missingForPromotion($draft))->toBe(['Projeto']);

    expect(fn () => shaping()->promote($draft))
        ->toThrow(ShapingIncompleteException::class, 'falta: Projeto.');
});

test('rabbit holes and no-gos are never required', function () {
    $project = Project::factory()->create();
    $draft = Activity::factory()->draft()->shaped()->create([
        'rabbit_holes' => null,
        'no_gos' => null,
    ]);

    expect(shaping()->missingForPromotion($draft, $project->id))->toBe([]);
});

test('promotion turns the same record into an epic in backlog with a zeroed spec timeline', function () {
    $project = Project::factory()->create();
    $draft = Activity::factory()->draft()->shaped(14)->create(['title' => 'Fila de puxar']);
    $id = $draft->id;

    $epic = shaping()->promote($draft, $project->id);

    expect($epic->id)->toBe($id)
        ->and($epic->type)->toBe(ActivityType::Epic)
        ->and($epic->status)->toBe(ActivityStatus::Backlog)
        ->and($epic->project_id)->toBe($project->id)
        ->and($epic->slug)->toBe('fila-de-puxar')
        ->and($epic->appetite_days)->toBe(14)
        // Nasce sem ciclo de Spec: nenhuma das quatro datas existe.
        ->and($epic->specStage())->toBeNull()
        ->and($epic->spec_enviada)->toBeNull()
        ->and($epic->spec_aprovada)->toBeNull()
        ->and($epic->spec_entregue)->toBeNull()
        ->and($epic->spec_validada)->toBeNull();

    $this->assertDatabaseCount('activities', 1);
});

test('only an idea can be promoted', function () {
    $epic = Activity::factory()->epic()->create();

    expect(fn () => shaping()->promote($epic, 1))
        ->toThrow(InvalidArgumentException::class);
});

test('the pitch template is stable: five sections, in order, empties kept', function () {
    $draft = Activity::factory()->draft()->create([
        'title' => 'Fila de puxar',
        'description' => 'Não sei o que pegar depois de terminar algo.',
        'appetite_days' => 7,
        'spec' => 'Coluna Pronto se auto-ordena por classe de serviço.',
        'rabbit_holes' => null,
        'no_gos' => 'Não vai reordenar o Backlog.',
    ]);

    expect(shaping()->pitch($draft))->toBe(<<<'MD'
        # Fila de puxar

        ## Dor

        Não sei o que pegar depois de terminar algo.

        ## Apetite

        7 dias

        ## Esboço

        Coluna Pronto se auto-ordena por classe de serviço.

        ## Rabbit holes

        _(vazio)_

        ## No-gos

        Não vai reordenar o Backlog.

        MD);
});

test('the pitch survives promotion unchanged', function () {
    $project = Project::factory()->create();
    $draft = Activity::factory()->draft()->shaped()->create(['title' => 'Mesma aposta']);

    $before = shaping()->pitch($draft);
    $epic = shaping()->promote($draft, $project->id);

    expect(shaping()->pitch($epic))->toBe($before);
});

test('the pitch flattens editor HTML instead of pasting tags into markdown', function () {
    $draft = Activity::factory()->draft()->create([
        'title' => 'Com editor',
        'description' => '<p>Primeira linha</p><p>Segunda linha</p>',
    ]);

    expect(shaping()->pitch($draft))
        ->toContain("Primeira linha\nSegunda linha")
        ->not->toContain('<p>');
});

test('the history aside says "ainda sem histórico" under three validated specs', function () {
    BaselineCut::query()->delete();

    validatedBet(5);
    validatedBet(9);

    $history = shaping()->appetiteHistory(7);

    expect($history['has_history'])->toBeFalse()
        ->and($history['sample_size'])->toBe(2)
        ->and($history['percentile'])->toBeNull()
        ->and($history['message'])->toContain('Ainda sem histórico');
});

test('the history aside quotes a real percentile from three validated specs up', function () {
    BaselineCut::query()->delete();

    validatedBet(4);
    validatedBet(8);
    validatedBet(12);
    validatedBet(20);

    $history = shaping()->appetiteHistory(8);

    expect($history['has_history'])->toBeTrue()
        ->and($history['sample_size'])->toBe(4)
        // Um apetite de 8 dias cobre as apostas de 4 e 8 dias: metade delas.
        ->and($history['percentile'])->toBe(50)
        ->and($history['median'])->toBe(10.0)
        ->and($history['message'])->toContain('n=4')
        ->and($history['message'])->toContain('cobre 50%');
});

test('the history aside is measured from the last baseline cut', function () {
    validatedBet(30, '2026-07-01 12:00');
    validatedBet(30, '2026-07-02 12:00');
    validatedBet(30, '2026-07-03 12:00');

    BaselineCut::query()->delete();
    BaselineCut::create(['reason' => 'Adoção do Fluxo Solo', 'cut_at' => Carbon::parse('2026-07-15 00:00')]);

    expect(shaping()->appetiteHistory(7)['sample_size'])->toBe(0);
});

test('the cached sample follows a new bet and a new cut instead of going stale', function () {
    BaselineCut::query()->delete();

    validatedBet(4);
    validatedBet(8);
    validatedBet(12);

    expect(shaping()->appetiteHistory(8)['sample_size'])->toBe(3);

    // Uma aposta a mais tem de aparecer sem ninguém limpar cache na mão.
    validatedBet(20);

    expect(shaping()->appetiteHistory(8)['sample_size'])->toBe(4);

    // E um corte novo zera a história, lida logo depois de já ter sido lida.
    BaselineCut::create(['reason' => 'Novo corte', 'cut_at' => now()->addDay()]);

    expect(shaping()->appetiteHistory(8)['sample_size'])->toBe(0);
});

test('an epic reopened after validation is a live bet again and leaves the history', function () {
    BaselineCut::query()->delete();

    $epic = validatedBet(6);

    ActivityStatusChange::factory()->create([
        'activity_id' => $epic->id,
        'from_status' => ActivityStatus::Done,
        'to_status' => ActivityStatus::Doing,
        'changed_at' => Carbon::parse('2026-08-02 12:00'),
    ]);
    $epic->update(['status' => ActivityStatus::Doing]);

    expect(app(FlowMetricsService::class)->validatedSpecSample())->toBe([]);
});
