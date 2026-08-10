<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Services\FlowMetricsService;
use Carbon\Carbon;

/**
 * A guarda de apetite estourado (issue #152).
 *
 * O consumo é medido na mesma janela da eficiência de fluxo — spec aprovada
 * até a validação, ou até agora enquanto ela não vem — e comparado contra o
 * apetite escolhido no shaping. Nada disso é gravado: cada fixture escreve o
 * histórico de status, que é o único lugar de onde a janela sai.
 *
 * @param  list<array{0: ActivityStatus, 1: string}>  $steps  Pares [status, momento], em ordem.
 */
function withBetHistory(Activity $activity, array $steps): Activity
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
 * Uma aposta viva: spec aprovada há $days dias, ainda não validada.
 */
function liveBet(?int $appetiteDays, float $days = 5, array $attributes = []): Activity
{
    // O status atual é irrelevante para a janela — ela sai do histórico —, e
    // nascer em Backlog evita esbarrar no limite de WIP ao montar a fixture.
    $epic = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Backlog,
        'appetite_days' => $appetiteDays,
        ...$attributes,
    ]);

    $approvedAt = Carbon::now()->copy()->subDays($days);

    return withBetHistory($epic, [
        [ActivityStatus::AwaitingApproval, $approvedAt->copy()->subDay()->toDateTimeString()],
        [ActivityStatus::Doing, $approvedAt->toDateTimeString()],
    ]);
}

function appetiteFlow(): FlowMetricsService
{
    return app(FlowMetricsService::class);
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 12:00');
    BaselineCut::query()->delete();
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-01-01 00:00')]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('o consumo corre da aprovação até agora enquanto a aposta está viva', function () {
    $epic = liveBet(appetiteDays: 14, days: 9);

    $consumption = appetiteFlow()->appetiteConsumption($epic);

    expect($consumption['consumed_days'])->toEqualWithDelta(9.0, 0.01)
        ->and($consumption['open'])->toBeTrue()
        ->and($consumption['appetite_days'])->toBe(14)
        ->and($consumption['label'])->toBe('9 de 14 dias')
        ->and($consumption['level'])->toBe('ok');
});

test('o relógio para na validação', function () {
    $epic = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Done,
        'appetite_days' => 14,
    ]);

    withBetHistory($epic, [
        [ActivityStatus::AwaitingApproval, '2026-07-01 12:00'],
        [ActivityStatus::Doing, '2026-07-02 12:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-09 12:00'],
        [ActivityStatus::Done, '2026-07-12 12:00'],
    ]);

    $consumption = appetiteFlow()->appetiteConsumption($epic);

    // 02/07 -> 12/07: dez dias, e o mês que passou desde então não conta.
    expect($consumption['consumed_days'])->toEqualWithDelta(10.0, 0.01)
        ->and($consumption['open'])->toBeFalse()
        ->and($consumption['level'])->toBe('ok');
});

test('uma spec reaberta depois de validada volta a consumir', function () {
    $epic = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Backlog,
        'appetite_days' => 14,
    ]);

    withBetHistory($epic, [
        [ActivityStatus::AwaitingApproval, '2026-07-01 12:00'],
        [ActivityStatus::Doing, '2026-07-02 12:00'],
        [ActivityStatus::Done, '2026-07-05 12:00'],
        [ActivityStatus::Doing, '2026-07-06 12:00'],
    ]);

    $consumption = appetiteFlow()->appetiteConsumption($epic);

    // A data de validação continua no histórico, mas a aposta está viva de
    // novo: a janela corre até agora, não até 05/07.
    expect($consumption['open'])->toBeTrue()
        ->and($consumption['consumed_days'])->toEqualWithDelta(39.0, 0.01)
        ->and($consumption['level'])->toBe('exceeded');
});

test('sem aprovação não há aposta para medir', function () {
    $epic = Activity::factory()->epic()->create(['appetite_days' => 7]);

    withBetHistory($epic, [
        [ActivityStatus::Backlog, '2026-08-01 12:00'],
        [ActivityStatus::AwaitingApproval, '2026-08-02 12:00'],
    ]);

    expect(appetiteFlow()->appetiteConsumption($epic))->toBeNull();
});

test('os limiares são os mesmos do aging: âmbar aos 80%, vermelho aos 100%', function (float $days, string $level) {
    $epic = liveBet(appetiteDays: 10, days: $days);

    expect(appetiteFlow()->appetiteConsumption($epic)['level'])->toBe($level);
})->with([
    'dentro do orçamento' => [7.0, 'ok'],
    'no limiar de atenção' => [8.0, 'warning'],
    'quase estourando' => [9.5, 'warning'],
    'no apetite exato' => [10.0, 'exceeded'],
    'estourado' => [13.0, 'exceeded'],
]);

test('o excedente é explícito quando o apetite estoura', function () {
    $epic = liveBet(appetiteDays: 10, days: 13);

    $consumption = appetiteFlow()->appetiteConsumption($epic);

    expect($consumption['level'])->toBe('exceeded')
        ->and($consumption['over_days'])->toEqualWithDelta(3.0, 0.01)
        ->and($consumption['over_label'])->toBe('+3d')
        ->and($consumption['label'])->toBe('13 de 10 dias');
});

test('sem apetite a guarda fica silenciosa', function () {
    $epic = liveBet(appetiteDays: null, days: 40);

    $consumption = appetiteFlow()->appetiteConsumption($epic);

    expect($consumption['level'])->toBe('no_appetite')
        ->and($consumption['appetite_days'])->toBeNull()
        ->and($consumption['ratio'])->toBeNull()
        ->and($consumption['over_days'])->toBeNull()
        ->and($consumption['label'])->toBe('sem apetite definido');
});

test('um apetite fora de faixa conta como não escolhido', function () {
    $epic = liveBet(appetiteDays: 0, days: 5);

    expect(appetiteFlow()->appetiteConsumption($epic)['level'])->toBe('no_appetite');
});

test('as apostas em andamento são as specs aprovadas e não validadas', function () {
    $live = liveBet(appetiteDays: 14, days: 3, attributes: ['title' => 'Aposta viva']);

    $delivered = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Backlog,
        'appetite_days' => 14,
        'title' => 'Entregue, esperando o sim',
    ]);
    withBetHistory($delivered, [
        [ActivityStatus::AwaitingApproval, '2026-08-01 12:00'],
        [ActivityStatus::Doing, '2026-08-02 12:00'],
        [ActivityStatus::AwaitingValidation, '2026-08-05 12:00'],
    ]);

    $validated = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Done,
        'appetite_days' => 14,
        'title' => 'Validada',
    ]);
    withBetHistory($validated, [
        [ActivityStatus::AwaitingApproval, '2026-08-01 12:00'],
        [ActivityStatus::Doing, '2026-08-02 12:00'],
        [ActivityStatus::Done, '2026-08-04 12:00'],
    ]);

    $pending = Activity::factory()->epic()->create([
        'status' => ActivityStatus::Backlog,
        'appetite_days' => 14,
        'title' => 'Ainda em aprovação',
    ]);
    withBetHistory($pending, [
        [ActivityStatus::AwaitingApproval, '2026-08-01 12:00'],
    ]);

    $titles = appetiteFlow()->liveBets()->map(fn (array $row): string => $row['epic']->title)->all();

    expect($titles)->toEqualCanonicalizing(['Aposta viva', 'Entregue, esperando o sim'])
        ->and($titles)->not->toContain('Validada')
        ->and($titles)->not->toContain('Ainda em aprovação');
});

test('as apostas estouradas vêm no topo e as sem apetite no fim', function () {
    liveBet(appetiteDays: 30, days: 3, attributes: ['title' => 'Tranquila']);
    liveBet(appetiteDays: 10, days: 9, attributes: ['title' => 'Chegando no limite']);
    liveBet(appetiteDays: 7, days: 14, attributes: ['title' => 'Estourada']);
    liveBet(appetiteDays: null, days: 40, attributes: ['title' => 'Sem apetite']);

    $order = appetiteFlow()->liveBets()->map(fn (array $row): string => $row['epic']->title)->all();

    expect($order)->toBe(['Estourada', 'Chegando no limite', 'Tranquila', 'Sem apetite']);
});
