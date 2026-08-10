<?php

use App\Enums\ActivityStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\GetPullQueueTool;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\Project;
use Carbon\Carbon;

/**
 * The MCP seam of the pull queue (issue #144): same order the Kanban
 * renders, plus the context a client needs to decide.
 */
function queuedInPronto(Activity $activity, string $at): Activity
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

/**
 * Call the tool through the real MCP seam and decode what a client would
 * actually receive. `TestResponse::content()` is protected, so the text is
 * read back through reflection rather than by calling the tool directly —
 * the point of these tests is the seam, not the handler in isolation.
 *
 * @return array<string, mixed>
 */
function pullQueuePayload(array $arguments = []): array
{
    $response = SoloBoardServer::tool(GetPullQueueTool::class, $arguments);

    $response->assertOk();

    $content = (new ReflectionMethod($response, 'content'))->invoke($response);

    return json_decode($content[0], true, flags: JSON_THROW_ON_ERROR);
}

test('get-pull-queue returns the queue in pull order with the motivo of each position', function () {
    $fifo = queuedInPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Padrão antiga']),
        '2026-08-01 09:00'
    );
    $atRisk = queuedInPronto(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(3)->toDateString())
            ->create(['title' => 'Data fixa apertada']),
        '2026-08-02 09:00'
    );
    $emergency = queuedInPronto(
        Activity::factory()->issue()->todo()->emergency('Produção fora do ar')
            ->create(['title' => 'Incêndio']),
        '2026-08-03 09:00'
    );

    $payload = pullQueuePayload();

    expect(array_column($payload['queue'], 'id'))->toBe([$emergency->id, $atRisk->id, $fifo->id]);
    expect(array_column($payload['queue'], 'reason'))->toBe(['emergency', 'fixed_date_at_risk', 'fifo']);
    expect(array_column($payload['queue'], 'position'))->toBe([1, 2, 3]);
    expect($payload['queue'][1]['reason_detail'])->toBe('em risco: faltam 3 dias');
});

test('each queued item reports class, project, client, due date and age', function () {
    $project = Project::factory()->create(['name' => 'SoloBoard']);
    $activity = queuedInPronto(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(2)->toDateString())->create([
            'title' => 'Entregar relatório',
            'project_id' => $project->id,
            'created_at' => now()->subDays(20),
        ]),
        now()->subDays(6)->toDateTimeString()
    );

    $item = pullQueuePayload()['queue'][0];

    expect($item['id'])->toBe($activity->id);
    expect($item['title'])->toBe('Entregar relatório');
    expect($item['service_class'])->toBe('fixed_date');
    expect($item['project'])->toBe('SoloBoard');
    expect($item['client'])->toBe($project->client?->name);
    expect($item['due_date'])->toBe(today()->addDays(2)->toDateString());
    expect($item['days_to_due_date'])->toBe(2);
    // Age counts from the board, and this item entered Pronto six days
    // ago — the twenty days since creation are not board time.
    expect($item['age_days'])->toBe(6);
});

test('the due date travels only for a Data fixa', function () {
    $standard = queuedInPronto(
        Activity::factory()->issue()->todo()->create([
            'title' => 'Padrão com data alvo',
            'due_date' => today()->addDays(2),
        ]),
        '2026-08-01 09:00'
    );
    $fixedDate = queuedInPronto(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(2)->toDateString())->create(),
        '2026-08-02 09:00'
    );

    $items = collect(pullQueuePayload()['queue'])->keyBy('id');

    expect($items[$standard->id]['reason'])->toBe('fifo');
    expect($items[$standard->id]['due_date'])->toBeNull();
    expect($items[$standard->id]['days_to_due_date'])->toBeNull();

    expect($items[$fixedDate->id]['due_date'])->toBe(today()->addDays(2)->toDateString());
    expect($items[$fixedDate->id]['days_to_due_date'])->toBe(2);
});

test('get-pull-queue reports the Fazendo WIP and the active Emergência as context', function () {
    config()->set('soloboard.wip_limit_doing', 2);

    Activity::factory()->issue()->doing()->create();
    $emergency = Activity::factory()->issue()->doing()->emergency('Servidor caiu')->create();
    queuedInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');

    $context = pullQueuePayload()['context'];

    expect($context['doing_wip'])->toBe(['count' => 2, 'limit' => 2]);
    expect($context['active_emergency']['id'])->toBe($emergency->id);
    expect($context['active_emergency']['reason'])->toBe('Servidor caiu');
});

test('the context reports no active Emergência when the slot is free', function () {
    queuedInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');

    expect(pullQueuePayload()['context']['active_emergency'])->toBeNull();
});

test('get-pull-queue carries no recommendation field', function () {
    queuedInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');

    $payload = pullQueuePayload();

    expect($payload['queue'][0])->not->toHaveKey('recommended');
    expect($payload['queue'][0])->not->toHaveKey('recommendation');
    expect($payload)->not->toHaveKey('recommendation');
});

test('get-pull-queue can be scoped to a project and still reports the full total', function () {
    $project = Project::factory()->create();

    $mine = queuedInPronto(
        Activity::factory()->issue()->todo()->create(['project_id' => $project->id]),
        '2026-08-01 09:00'
    );
    queuedInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-02 09:00');

    $payload = pullQueuePayload(['project_id' => $project->id]);

    expect(array_column($payload['queue'], 'id'))->toBe([$mine->id]);
    expect($payload['total_in_pronto'])->toBe(1);
});

test('get-pull-queue honours the limit while reporting the full total', function () {
    queuedInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');
    queuedInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-02 09:00');
    queuedInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-03 09:00');

    $payload = pullQueuePayload(['limit' => 2]);

    expect($payload['queue'])->toHaveCount(2);
    expect($payload['total_in_pronto'])->toBe(3);
});

test('get-pull-queue exposes the configured risk window', function () {
    config()->set('soloboard.fixed_date_risk_days', 3);

    expect(pullQueuePayload()['risk_window_days'])->toBe(3);
});

test('get-pull-queue rejects an unknown project', function () {
    SoloBoardServer::tool(GetPullQueueTool::class, ['project_id' => 999999])
        ->assertHasErrors(['Project not found. Use list-projects to find available project ids.']);
});

// ── Fome de Intangível (issue #153) ──────────────────────────────────────

/**
 * Deixa o quadro com exatamente um corte de baseline, no momento dado — a
 * migration semeia o corte de adoção com `cut_at = now()` do relógio real.
 */
function onlyPullQueueCutAt(?string $at): void
{
    BaselineCut::query()->delete();

    if ($at !== null) {
        BaselineCut::factory()->create(['cut_at' => Carbon::parse($at)]);
    }
}

test('the context carries the intangible hunger, measured from the last conclusion', function () {
    Carbon::setTestNow('2026-08-10 12:00:00');
    onlyPullQueueCutAt('2026-06-01 12:00');

    $concluded = Activity::factory()->issue()->intangible()->done()->create();
    ActivityStatusChange::query()->where('activity_id', $concluded->id)->delete();
    ActivityStatusChange::factory()->create([
        'activity_id' => $concluded->id,
        'to_status' => ActivityStatus::Done,
        'changed_at' => Carbon::parse('2026-07-20 12:00'),
    ]);

    queuedInPronto(Activity::factory()->issue()->intangible()->todo()->create(), '2026-08-01 09:00');

    $hunger = pullQueuePayload()['context']['intangible_hunger'];

    expect($hunger['days'])->toEqualWithDelta(21.0, 0.01)
        ->and($hunger['threshold_days'])->toBe(14)
        ->and($hunger['starving'])->toBeTrue()
        ->and($hunger['anchor'])->toBe('completion')
        ->and($hunger['last_completed_at'])->toBe('2026-07-20 12:00:00')
        ->and($hunger['ready_in_pronto'])->toBe(1)
        ->and($hunger['label'])->toBe('última conclusão há 21 dias');

    Carbon::setTestNow();
});

test('the context reports the hunger as fed while it is inside the threshold', function () {
    Carbon::setTestNow('2026-08-10 12:00:00');
    onlyPullQueueCutAt('2026-06-01 12:00');

    $concluded = Activity::factory()->issue()->intangible()->done()->create();
    ActivityStatusChange::query()->where('activity_id', $concluded->id)->delete();
    ActivityStatusChange::factory()->create([
        'activity_id' => $concluded->id,
        'to_status' => ActivityStatus::Done,
        'changed_at' => Carbon::parse('2026-08-07 12:00'),
    ]);

    expect(pullQueuePayload()['context']['intangible_hunger'])
        ->days->toEqualWithDelta(3.0, 0.01)
        ->starving->toBeFalse();

    Carbon::setTestNow();
});

test('a cold start is published as starving, anchored on the cut, with an empty pantry', function () {
    Carbon::setTestNow('2026-08-10 12:00:00');
    onlyPullQueueCutAt('2026-07-01 12:00');

    $hunger = pullQueuePayload()['context']['intangible_hunger'];

    expect($hunger['starving'])->toBeTrue()
        ->and($hunger['anchor'])->toBe('cut')
        ->and($hunger['last_completed_at'])->toBeNull()
        ->and($hunger['ready_in_pronto'])->toBe(0)
        ->and($hunger['label'])->toBe('nenhuma conclusão desde o corte, há 40 dias');

    Carbon::setTestNow();
});

test('the server instructions describe the intangible hunger, so an agent reads it before the tool', function () {
    $instructions = (new ReflectionClass(SoloBoardServer::class))
        ->getDefaultProperties()['instructions'];

    expect($instructions)
        ->toContain('intangible hunger')
        ->toContain('intangible_starvation_days')
        ->toContain('ready_in_pronto');
});
