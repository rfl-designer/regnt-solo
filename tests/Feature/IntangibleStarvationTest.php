<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Services\FlowMetricsService;
use Carbon\Carbon;

/**
 * A fome de Intangível (issue #153).
 *
 * A métrica é o tempo desde a última *conclusão* de um item classe
 * Intangível — entrada em Feito, lida do histórico de status como todas as
 * outras métricas de fluxo. Puxar não zera; só concluir zera. Sem nenhuma
 * conclusão no histórico o relógio ancora no corte vigente da baseline, e o
 * alerta dispara independentemente do limiar — inclusive num corte de hoje:
 * "nunca concluí um Intangível" é a fome máxima, e o limiar só serve para
 * envelhecer uma conclusão de verdade.
 */

/**
 * Grava uma entrada em Feito para a atividade no momento dado, apagando o
 * que o observer tiver registrado — a fixture declara um histórico só.
 *
 * @param  list<array{0: ActivityStatus, 1: string}>  $steps  Pares [status, momento], em ordem.
 */
function withStarvationHistory(Activity $activity, array $steps): Activity
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

    return $activity;
}

function starvation(): array
{
    return app(FlowMetricsService::class)->intangibleStarvation();
}

/**
 * Leave the board with exactly one baseline cut, at the given moment. The
 * migration seeds the adoption cut with `cut_at = now()`, which lands at the
 * real clock rather than the frozen one — every fixture that cares about the
 * cut has to state it.
 */
function onlyCutAt(?string $at): void
{
    BaselineCut::query()->delete();

    if ($at !== null) {
        BaselineCut::factory()->create(['cut_at' => Carbon::parse($at)]);
    }
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the hunger is the days since the last Intangível entered Feito', function () {
    withStarvationHistory(
        Activity::factory()->issue()->intangible()->done()->create(),
        [
            [ActivityStatus::Todo, '2026-07-20 09:00'],
            [ActivityStatus::Done, '2026-08-07 12:00'],
        ],
    );

    $hunger = starvation();

    expect($hunger['days'])->toEqualWithDelta(3.0, 0.01)
        ->and($hunger['anchor'])->toBe('completion')
        ->and($hunger['starving'])->toBeFalse()
        ->and($hunger['label'])->toBe('última conclusão há 3 dias');
});

test('the hunger fires once the last Intangível conclusion is older than the threshold', function () {
    withStarvationHistory(
        Activity::factory()->issue()->intangible()->done()->create(),
        [
            [ActivityStatus::Todo, '2026-06-01 09:00'],
            [ActivityStatus::Done, '2026-07-20 12:00'],
        ],
    );

    $hunger = starvation();

    expect($hunger['days'])->toEqualWithDelta(21.0, 0.01)
        ->and($hunger['starving'])->toBeTrue()
        ->and($hunger['threshold'])->toBe(14)
        ->and($hunger['headline'])->toBe('Fome de Intangível: última conclusão há 21 dias');
});

test('the threshold comes from config', function () {
    config()->set('soloboard.intangible_starvation_days', 30);

    withStarvationHistory(
        Activity::factory()->issue()->intangible()->done()->create(),
        [
            [ActivityStatus::Todo, '2026-06-01 09:00'],
            [ActivityStatus::Done, '2026-07-20 12:00'],
        ],
    );

    expect(starvation())
        ->threshold->toBe(30)
        ->starving->toBeFalse();
});

test('pulling an Intangível without concluding it does not reset the hunger', function () {
    // Concluída há muito tempo, e outra puxada para Fazendo hoje: a fome
    // continua contando da conclusão, não do que começou.
    withStarvationHistory(
        Activity::factory()->issue()->intangible()->done()->create(),
        [
            [ActivityStatus::Todo, '2026-06-01 09:00'],
            [ActivityStatus::Done, '2026-07-01 12:00'],
        ],
    );

    withStarvationHistory(
        Activity::factory()->issue()->intangible()->doing()->create(),
        [
            [ActivityStatus::Todo, '2026-08-08 09:00'],
            [ActivityStatus::Doing, '2026-08-10 09:00'],
        ],
    );

    expect(starvation())
        ->days->toEqualWithDelta(40.0, 0.01)
        ->anchor->toBe('completion')
        ->starving->toBeTrue();
});

test('conclusions of other service classes never feed the hunger', function () {
    withStarvationHistory(
        Activity::factory()->issue()->done()->create(),
        [
            [ActivityStatus::Todo, '2026-08-08 09:00'],
            [ActivityStatus::Done, '2026-08-10 09:00'],
        ],
    );

    onlyCutAt('2026-07-01 12:00');

    expect(starvation())
        ->anchor->toBe('cut')
        ->days->toEqualWithDelta(40.0, 0.01)
        ->starving->toBeTrue();
});

test('a cold start anchors on the current baseline cut and fires', function () {
    onlyCutAt('2026-07-01 12:00');

    $hunger = starvation();

    expect($hunger['anchor'])->toBe('cut')
        ->and($hunger['last_completed_at'])->toBeNull()
        ->and($hunger['days'])->toEqualWithDelta(40.0, 0.01)
        ->and($hunger['starving'])->toBeTrue()
        ->and($hunger['label'])->toBe('nenhuma conclusão desde o corte, há 40 dias');
});

test('a cold start fires even when the cut is younger than the threshold', function () {
    // O limiar mede a idade de uma conclusão; na partida a frio não há
    // conclusão nenhuma para envelhecer. Um corte de três dias não pode
    // comprar onze dias de silêncio.
    onlyCutAt('2026-08-07 12:00');

    expect(starvation())
        ->anchor->toBe('cut')
        ->days->toEqualWithDelta(3.0, 0.01)
        ->starving->toBeTrue()
        ->label->toBe('nenhuma conclusão desde o corte, há 3 dias')
        ->headline->toBe('Fome de Intangível: nenhuma conclusão desde o corte, há 3 dias');
});

test('a cut made today already reports the hunger, in words that fit a zero-day clock', function () {
    onlyCutAt('2026-08-10 08:00');

    expect(starvation())
        ->anchor->toBe('cut')
        ->starving->toBeTrue()
        ->label->toBe('nenhuma conclusão desde o corte, feito hoje');
});

test('a board that started today with no cut also starves', function () {
    onlyCutAt(null);

    withStarvationHistory(
        Activity::factory()->issue()->todo()->create(),
        [[ActivityStatus::Todo, '2026-08-10 09:00']],
    );

    expect(starvation())
        ->anchor->toBe('board')
        ->starving->toBeTrue()
        ->label->toBe('nenhuma conclusão registrada desde que o quadro começou, hoje');
});

test('a cold start on a board with no cut anchors on the first recorded change', function () {
    onlyCutAt(null);

    withStarvationHistory(
        Activity::factory()->issue()->todo()->create(),
        [[ActivityStatus::Todo, '2026-07-25 12:00']],
    );

    expect(starvation())
        ->anchor->toBe('board')
        ->days->toEqualWithDelta(16.0, 0.01)
        ->starving->toBeTrue()
        ->label->toBe('nenhuma conclusão registrada, há 16 dias');
});

test('a board with no history at all has no clock and stays silent', function () {
    onlyCutAt(null);

    expect(starvation())
        ->anchor->toBe('none')
        ->days->toBe(0.0)
        ->starving->toBeFalse()
        ->headline->toBeNull();
});

test('a conclusion older than the current cut still counts, so a cut cannot reset the hunger', function () {
    withStarvationHistory(
        Activity::factory()->issue()->intangible()->done()->create(),
        [
            [ActivityStatus::Todo, '2026-05-01 09:00'],
            [ActivityStatus::Done, '2026-06-10 12:00'],
        ],
    );

    onlyCutAt('2026-08-09 12:00');

    expect(starvation())
        ->anchor->toBe('completion')
        ->days->toEqualWithDelta(61.0, 0.01)
        ->starving->toBeTrue();
});

test('the hunger reports how many Intangíveis are sitting in Pronto', function () {
    onlyCutAt('2026-07-01 12:00');

    Activity::factory()->issue()->intangible()->todo()->create(['title' => 'Refatorar o observer']);
    Activity::factory()->issue()->intangible()->todo()->create(['title' => 'Documentar o MCP']);
    Activity::factory()->issue()->todo()->create(['title' => 'Padrão em Pronto']);
    Activity::factory()->issue()->intangible()->doing()->create(['title' => 'Já puxada']);

    expect(starvation())->ready_count->toBe(2);

    expect(app(FlowMetricsService::class)->readyIntangibles()->pluck('title')->all())
        ->toBe(['Refatorar o observer', 'Documentar o MCP']);
});

test('an empty pantry does not suppress the hunger', function () {
    onlyCutAt('2026-07-01 12:00');

    expect(starvation())
        ->ready_count->toBe(0)
        ->starving->toBeTrue();
});
