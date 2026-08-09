<?php

use App\Enums\ActivityStatus;
use App\Enums\HillPosition;
use App\Enums\ServiceClass;
use App\Enums\UpdateUrgency;
use App\Exceptions\UpdateAlreadySentException;
use App\Exceptions\UpdateDraftHasManualEditsException;
use App\Models\Activity;
use App\Models\BaselineCut;
use App\Models\Client;
use App\Models\ClientUpdate;
use App\Models\MorningRitual;
use App\Models\Project;
use App\Services\ClientUpdateService;
use App\Services\FlowMetricsService;

/**
 * O gerador do update semanal (issue #149): a janela, os quatro blocos, a
 * fila por urgência e o ato de enviar.
 *
 * As datas dos blocos vêm do histórico de status (o ciclo de vida da Spec,
 * issue #146), então todo fixture aqui escreve esse histórico à mão — é ele,
 * e nenhuma coluna de `activities`, que o serviço lê.
 */
function updateService(): ClientUpdateService
{
    return app(ClientUpdateService::class);
}

/**
 * Um cliente com um projeto — o caminho normal pelo qual uma spec pertence
 * a alguém (cliente efetivo via projeto).
 *
 * @return array{0: Client, 1: Project}
 */
function clientWithProject(array $attributes = []): array
{
    $client = Client::factory()->create($attributes);
    $project = Project::factory()->create(['client_id' => $client->id]);

    return [$client, $project];
}

beforeEach(function () {
    // Sexta-feira, 12:00 no fuso de negócio (UTC-3).
    $this->travelTo('2026-08-07 15:00:00');
});

test('a janela do primeiro update cobre sete dias', function () {
    [$client] = clientWithProject();

    expect(updateService()->windowStart($client)->toDateTimeString())
        ->toBe(now()->subDays(7)->toDateTimeString());
});

test('depois do primeiro envio a janela começa no último envio', function () {
    [$client] = clientWithProject();

    ClientUpdate::factory()->for($client)->sent('2026-08-05 10:00:00')->create();

    expect(updateService()->windowStart($client)->toDateTimeString())
        ->toBe('2026-08-05 10:00:00');
});

test('a janela não tem teto: três semanas de atraso viram três semanas de update', function () {
    [$client] = clientWithProject();

    ClientUpdate::factory()->for($client)->sent('2026-07-17 09:00:00')->create();

    $start = updateService()->windowStart($client);

    expect($start->toDateTimeString())->toBe('2026-07-17 09:00:00')
        ->and((int) $start->diffInDays(now()))->toBe(21);
});

test('o bloco Entregue conta o que entrou em validação na janela, com o estado', function () {
    [$client, $project] = clientWithProject();

    $delivered = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Portal de faturas',
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($delivered, [
        [ActivityStatus::AwaitingApproval, '2026-07-20 09:00:00'],
        [ActivityStatus::Doing, '2026-07-22 09:00:00'],
        [ActivityStatus::AwaitingValidation, '2026-08-05 09:00:00'],
    ]);

    $validated = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Login por link mágico',
        'status' => ActivityStatus::Done,
    ]);
    withSpecHistory($validated, [
        [ActivityStatus::AwaitingApproval, '2026-07-10 09:00:00'],
        [ActivityStatus::Doing, '2026-07-12 09:00:00'],
        [ActivityStatus::AwaitingValidation, '2026-08-03 09:00:00'],
        [ActivityStatus::Done, '2026-08-06 09:00:00'],
    ]);

    $blocks = collect(updateService()->blocks($client))->keyBy('key');

    expect($blocks['entregue']['items'])->toHaveCount(2)
        ->and(collect($blocks['entregue']['items'])->firstWhere('id', $delivered->id)['detail'])
        ->toBe('entregue, aguardando sua validação')
        ->and(collect($blocks['entregue']['items'])->firstWhere('id', $validated->id)['detail'])
        ->toBe('validada ✓');
});

test('o bloco Entregue ignora a entrega anterior à janela', function () {
    [$client, $project] = clientWithProject();

    ClientUpdate::factory()->for($client)->sent('2026-08-05 10:00:00')->create();

    $old = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($old, [
        [ActivityStatus::AwaitingApproval, '2026-07-01 09:00:00'],
        [ActivityStatus::Doing, '2026-07-02 09:00:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-30 09:00:00'],
    ]);

    $blocks = collect(updateService()->blocks($client))->keyBy('key');

    expect($blocks['entregue']['items'])->toBeEmpty()
        // Continua sendo trabalho na mão do cliente — só não é notícia desta semana.
        ->and($blocks['esperando_voce']['items'])->toHaveCount(1);
});

test('o bloco Em andamento traz a frase do hill e a omite quando não há posição', function () {
    [$client, $project] = clientWithProject();

    $withHill = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Relatório fiscal',
        'status' => ActivityStatus::Doing,
        'hill_position' => HillPosition::Downhill,
    ]);
    withSpecHistory($withHill, [
        [ActivityStatus::AwaitingApproval, '2026-07-25 09:00:00'],
        [ActivityStatus::Doing, '2026-07-28 09:00:00'],
    ]);

    $withoutHill = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Integração com o banco',
        'status' => ActivityStatus::Todo,
    ]);
    withSpecHistory($withoutHill, [
        [ActivityStatus::AwaitingApproval, '2026-07-26 09:00:00'],
        [ActivityStatus::Todo, '2026-07-29 09:00:00'],
    ]);

    $blocks = collect(updateService()->blocks($client))->keyBy('key');
    $items = collect($blocks['em_andamento']['items']);

    expect($items)->toHaveCount(2)
        ->and($items->firstWhere('id', $withHill->id)['detail'])->toBe('em execução')
        ->and($items->firstWhere('id', $withoutHill->id)['detail'])->toBeNull();

    $text = updateService()->compose($client);

    expect($text)->toContain('- Relatório fiscal — em execução')
        ->and($text)->toContain('- Integração com o banco'."\n");
});

test('o bloco Esperando você lista o que está na mão do cliente agora, com desde quando', function () {
    [$client, $project] = clientWithProject();

    $waiting = Activity::factory()->epic()->awaitingApproval()->create([
        'project_id' => $project->id,
        'title' => 'Spec do checkout',
    ]);
    // `waiting_since` só é carimbado pelo observer, nunca aceito de um
    // payload (issue #142) — envelhecer a espera num teste é escrever a
    // coluna por baixo do modelo.
    $waiting->forceFill(['waiting_since' => now()->subDays(3)])->saveQuietly();
    withSpecHistory($waiting, [
        [ActivityStatus::AwaitingApproval, now()->subDays(3)->toDateTimeString()],
    ]);

    $blocks = collect(updateService()->blocks($client))->keyBy('key');

    expect($blocks['esperando_voce']['items'])->toHaveCount(1)
        ->and($blocks['esperando_voce']['items'][0]['detail'])
        ->toBe('aguardando sua aprovação há 3 dias')
        ->and($blocks['esperando_voce']['items'][0]['id'])->toBe($waiting->id);
});

test('o bloco Próximo lê a fila de puxar em nível spec, sem SLE utilizável', function () {
    [$client, $project] = clientWithProject();

    $epic = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Área do cliente',
        'status' => ActivityStatus::Doing,
    ]);
    withSpecHistory($epic, [
        [ActivityStatus::AwaitingApproval, '2026-07-20 09:00:00'],
        [ActivityStatus::Doing, '2026-07-22 09:00:00'],
    ]);

    // Duas fatias do mesmo Épico, ambas em Pronto: o update fala do Épico,
    // uma linha só.
    Activity::factory()->issue()->todo()->forParent($epic)->create(['project_id' => $project->id]);
    Activity::factory()->issue()->todo()->forParent($epic)->create(['project_id' => $project->id]);

    $blocks = collect(updateService()->blocks($client))->keyBy('key');

    expect($blocks['proximo']['items'])->toHaveCount(1)
        ->and($blocks['proximo']['items'][0]['title'])->toBe('Área do cliente')
        ->and($blocks['proximo']['items'][0]['detail'])->toBeNull();
});

test('o bloco Próximo cita a previsão quando a SLE é utilizável', function () {
    config(['soloboard.sle_minimum_sample' => 1, 'soloboard.sle_percentile' => 85]);

    // O corte de adoção gravado pela migração é posterior ao relógio deste
    // teste, e a população da SLE é o que foi concluído *desde* o corte
    // (issue #145). Sem apagá-lo, a amostra seria vazia por construção.
    BaselineCut::query()->delete();

    [$client, $project] = clientWithProject();

    $finished = Activity::factory()->issue()->done()->create(['completed_at' => now()->subDay()]);
    withSpecHistory($finished, [
        [ActivityStatus::Todo, '2026-07-31 09:00:00'],
        [ActivityStatus::Done, '2026-08-06 09:00:00'],
    ]);

    Activity::factory()->epic()->todo()->create([
        'project_id' => $project->id,
        'title' => 'Exportação de dados',
    ]);

    app(FlowMetricsService::class)->forget();

    $blocks = collect(updateService()->blocks($client))->keyBy('key');

    expect($blocks['proximo']['items'])->toHaveCount(1)
        ->and($blocks['proximo']['items'][0]['detail'])->toStartWith('previsão de até ');
});

test('o template omite os blocos vazios', function () {
    [$client, $project] = clientWithProject();

    $waiting = Activity::factory()->epic()->awaitingApproval()->create([
        'project_id' => $project->id,
        'title' => 'Spec do checkout',
        'waiting_since' => now()->subDay(),
    ]);
    withSpecHistory($waiting, [
        [ActivityStatus::AwaitingApproval, now()->subDay()->toDateTimeString()],
    ]);

    $text = updateService()->compose($client);

    expect($text)->toContain('**Esperando você**')
        ->and($text)->not->toContain('**Entregue**')
        ->and($text)->not->toContain('**Em andamento**')
        ->and($text)->not->toContain('**Próximo**');
});

test('sem nada a contar, o update diz isso — e ainda diz de que período fala', function () {
    [$client] = clientWithProject();

    $text = updateService()->compose($client);

    expect($text)->toContain('Sem novidades por aqui desde o último update.')
        ->and($text)->toContain('**Update — 31/07 a 07/08**');
});

test('o template fala em nível spec: as filhas de um Épico não são nomeadas', function () {
    [$client, $project] = clientWithProject();

    $epic = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Área do cliente',
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($epic, [
        [ActivityStatus::AwaitingApproval, '2026-07-20 09:00:00'],
        [ActivityStatus::AwaitingValidation, '2026-08-06 09:00:00'],
    ]);

    $child = Activity::factory()->issue()->forParent($epic)->create([
        'project_id' => $project->id,
        'title' => 'Migration da tabela de sessões',
        'status' => ActivityStatus::AwaitingValidation,
        'waiting_for' => 'Cliente',
    ]);
    withSpecHistory($child, [
        [ActivityStatus::AwaitingValidation, '2026-08-06 09:00:00'],
    ]);

    $text = updateService()->compose($client);

    expect($text)->toContain('Área do cliente')
        ->and($text)->not->toContain('Migration da tabela de sessões');
});

test('o template fala de um cliente só', function () {
    [$client, $project] = clientWithProject();
    [$other, $otherProject] = clientWithProject();

    $mine = Activity::factory()->epic()->awaitingApproval()->create([
        'project_id' => $project->id,
        'title' => 'Minha spec',
        'waiting_since' => now()->subDay(),
    ]);
    withSpecHistory($mine, [[ActivityStatus::AwaitingApproval, now()->subDay()->toDateTimeString()]]);

    $theirs = Activity::factory()->epic()->awaitingApproval()->create([
        'project_id' => $otherProject->id,
        'title' => 'Spec do outro',
        'waiting_since' => now()->subDay(),
    ]);
    withSpecHistory($theirs, [[ActivityStatus::AwaitingApproval, now()->subDay()->toDateTimeString()]]);

    expect(updateService()->compose($client))->toContain('Minha spec')
        ->and(updateService()->compose($client))->not->toContain('Spec do outro');
});

test('uma task avulsa vinculada direto ao cliente é nível spec', function () {
    $client = Client::factory()->create();

    $task = Activity::factory()->awaitingApproval()->create([
        'client_id' => $client->id,
        'title' => 'Renovar o domínio',
        'waiting_since' => now()->subDays(2),
    ]);
    withSpecHistory($task, [[ActivityStatus::AwaitingApproval, now()->subDays(2)->toDateTimeString()]]);

    expect(updateService()->compose($client))->toContain('Renovar o domínio');
});

test('a fila ordena por urgência e a badge conta só os devidos', function () {
    $today = MorningRitual::businessNow();

    $overdue = Client::factory()->create([
        'name' => 'Atrasado',
        'update_day' => $today->copy()->subDays(2)->dayOfWeekIso,
        'update_time' => '09:00',
    ]);

    $dueToday = Client::factory()->create([
        'name' => 'Vence hoje',
        'update_day' => $today->dayOfWeekIso,
        'update_time' => '09:00',
    ]);

    $onTrack = Client::factory()->create([
        'name' => 'Em dia',
        'update_day' => $today->dayOfWeekIso,
        'update_time' => '09:00',
    ]);
    ClientUpdate::factory()->for($onTrack)->sent(now()->toDateTimeString())->create();

    Client::factory()->archived()->create(['name' => 'Arquivado']);

    $queue = updateService()->queue();

    expect($queue->pluck('client.name')->all())->toBe(['Atrasado', 'Vence hoje', 'Em dia'])
        ->and($queue[0]->urgency)->toBe(UpdateUrgency::Overdue)
        ->and($queue[0]->daysLate())->toBe(2)
        ->and($queue[1]->urgency)->toBe(UpdateUrgency::DueToday)
        ->and($queue[2]->urgency)->toBe(UpdateUrgency::OnTrack)
        ->and(updateService()->dueCount())->toBe(2);

    expect($overdue->fresh())->not->toBeNull()
        ->and($dueToday->fresh())->not->toBeNull();
});

test('marcar como enviado grava a data e o próximo rascunho cobre desde aí', function () {
    [$client, $project] = clientWithProject();

    $draft = updateService()->generate($client);
    $sent = updateService()->markSent($draft);

    expect($sent->sent_at)->not->toBeNull()
        ->and($client->fresh()->draftUpdate())->toBeNull()
        ->and(updateService()->windowStart($client->fresh())->toDateTimeString())
        ->toBe($sent->sent_at->toDateTimeString());

    // E o relógio da cadência zerou: o cliente sai dos devidos.
    expect(updateService()->entryFor($client->fresh())->urgency)->toBe(UpdateUrgency::OnTrack);
});

test('marcar como enviado duas vezes é recusado', function () {
    [$client] = clientWithProject();

    $sent = updateService()->markSent(updateService()->generate($client));

    updateService()->markSent($sent);
})->throws(UpdateAlreadySentException::class);

test('regerar um rascunho editado à mão é recusado sem confirmação', function () {
    [$client] = clientWithProject();

    $draft = updateService()->generate($client);
    $draft->update(['content' => 'Oi! Escrevi na mão.']);

    updateService()->generate($client->fresh());
})->throws(UpdateDraftHasManualEditsException::class);

test('regerar com confirmação descarta a edição e remonta do quadro', function () {
    [$client, $project] = clientWithProject();

    $draft = updateService()->generate($client);
    $draft->update(['content' => 'Oi! Escrevi na mão.']);

    $waiting = Activity::factory()->epic()->awaitingApproval()->create([
        'project_id' => $project->id,
        'title' => 'Spec nova',
        'waiting_since' => now(),
    ]);
    withSpecHistory($waiting, [[ActivityStatus::AwaitingApproval, now()->toDateTimeString()]]);

    $regenerated = updateService()->generate($client->fresh(), force: true);

    expect($regenerated->id)->toBe($draft->id)
        ->and($regenerated->content)->toContain('Spec nova')
        ->and($regenerated->hasManualEdits())->toBeFalse();
});

test('regerar um rascunho intocado não pede confirmação', function () {
    [$client] = clientWithProject();

    $draft = updateService()->generate($client);
    $again = updateService()->generate($client->fresh());

    expect($again->id)->toBe($draft->id);
});

test('gerar não envia nada', function () {
    [$client] = clientWithProject();

    $draft = updateService()->generate($client);

    expect($draft->sent_at)->toBeNull()
        ->and($draft->isDraft())->toBeTrue()
        ->and(updateService()->history($client))->toBeEmpty();
});

test('o histórico traz o que o cliente recebeu, do mais recente ao mais antigo', function () {
    [$client] = clientWithProject();

    ClientUpdate::factory()->for($client)->sent('2026-07-24 09:00:00')->create(['content' => 'Antigo']);
    ClientUpdate::factory()->for($client)->sent('2026-07-31 09:00:00')->create(['content' => 'Recente']);
    ClientUpdate::factory()->for($client)->create(['content' => 'Rascunho']);

    $history = updateService()->history($client);

    expect($history->pluck('content')->all())->toBe(['Recente', 'Antigo']);
});

test('uma Emergência do cliente lidera o bloco Próximo, como na fila de puxar', function () {
    [$client, $project] = clientWithProject();

    Activity::factory()->epic()->todo()->create([
        'project_id' => $project->id,
        'title' => 'Trabalho normal',
    ]);

    Activity::factory()->epic()->todo()->create([
        'project_id' => $project->id,
        'title' => 'Servidor fora do ar',
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada.',
    ]);

    $blocks = collect(updateService()->blocks($client))->keyBy('key');

    expect($blocks['proximo']['items'][0]['title'])->toBe('Servidor fora do ar');
});

test('a frase do hill só sai de uma spec, nunca de uma filha', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);

    $epic = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Área do cliente',
        'status' => ActivityStatus::Doing,
        'hill_position' => HillPosition::Uphill,
    ]);
    withSpecHistory($epic, [
        [ActivityStatus::AwaitingApproval, now()->subDays(10)->toDateTimeString()],
        [ActivityStatus::Doing, now()->subDays(8)->toDateTimeString()],
    ]);

    // A filha tem hill marcado e é deliberadamente ignorada: o update fala
    // de compromissos, e o compromisso é o Épico.
    $child = Activity::factory()->issue()->forParent($epic)->create([
        'project_id' => $project->id,
        'title' => 'Migration das sessões',
        'status' => ActivityStatus::Doing,
        'hill_position' => HillPosition::Downhill,
    ]);
    withSpecHistory($child, [[ActivityStatus::Doing, now()->subDay()->toDateTimeString()]]);

    $text = app(ClientUpdateService::class)->compose($client);

    expect($text)->toContain('- Área do cliente — em descoberta')
        ->and($text)->not->toContain('Migration das sessões');
});
