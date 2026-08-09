<?php

use App\Enums\ActivityStatus;
use App\Enums\HillPosition;
use App\Enums\ServiceClass;
use App\Enums\UpdateTrigger;
use App\Enums\UpdateUrgency;
use App\Exceptions\UpdateAlreadySentException;
use App\Exceptions\UpdateDraftHasManualEditsException;
use App\Models\Activity;
use App\Models\BaselineCut;
use App\Models\Client;
use App\Models\ClientUpdate;
use App\Models\MorningRitual;
use App\Models\Project;
use App\Services\ClientUpdateQueueEntry;
use App\Services\ClientUpdateService;
use App\Services\FlowMetricsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

test('no dia do compromisso o cliente vence hoje antes da hora-alvo, e não sete dias atrasado', function () {
    // 11:00 no fuso de negócio, contra uma hora-alvo às 18:00 — o
    // compromisso é hoje e ainda não chegou.
    $this->travelTo('2026-08-07 14:00:00');

    $client = Client::factory()->create([
        'update_day' => MorningRitual::businessNow()->dayOfWeekIso,
        'update_time' => '18:00',
    ]);

    $entry = updateService()->entryFor($client);

    expect($entry->urgency)->toBe(UpdateUrgency::DueToday)
        ->and($entry->daysLate())->toBe(0)
        // O instante cobrado guarda a hora-alvo, ainda que ela não decida
        // nada sobre a urgência.
        ->and($entry->dueAt->format('Y-m-d H:i'))->toBe('2026-08-07 18:00');
});

test('depois da hora-alvo ele continua vencendo hoje, e só amanhã fica atrasado de um dia', function () {
    $this->travelTo('2026-08-07 23:00:00'); // 20:00 no fuso de negócio.

    $client = Client::factory()->create([
        'update_day' => MorningRitual::businessNow()->dayOfWeekIso,
        'update_time' => '09:00',
    ]);

    expect(updateService()->entryFor($client)->urgency)->toBe(UpdateUrgency::DueToday);

    $this->travelTo('2026-08-08 14:00:00');

    $tomorrow = updateService()->entryFor($client->fresh());

    expect($tomorrow->urgency)->toBe(UpdateUrgency::Overdue)
        ->and($tomorrow->daysLate())->toBe(1);
});

test('a virada do dia é a do fuso de negócio, não a do UTC', function () {
    // 22:00 de sexta em Recife já é sábado em UTC. Quem vence "sexta" vence
    // agora; quem vence "sábado" ainda está atrasado da semana passada.
    $this->travelTo('2026-08-08 01:00:00');

    expect(MorningRitual::businessNow()->format('Y-m-d'))->toBe('2026-08-07');

    $friday = Client::factory()->create(['update_day' => 5, 'update_time' => '09:00']);
    $saturday = Client::factory()->create(['update_day' => 6, 'update_time' => '09:00']);

    expect(updateService()->entryFor($friday)->urgency)->toBe(UpdateUrgency::DueToday)
        ->and(updateService()->entryFor($saturday)->urgency)->toBe(UpdateUrgency::Overdue)
        ->and(updateService()->entryFor($saturday)->daysLate())->toBe(6);
});

test('enviar no dia do compromisso cobre o compromisso, mesmo antes da hora-alvo', function () {
    $this->travelTo('2026-08-07 14:00:00'); // 11:00 no fuso de negócio.

    $client = Client::factory()->create([
        'update_day' => MorningRitual::businessNow()->dayOfWeekIso,
        'update_time' => '18:00',
    ]);
    ClientUpdate::factory()->for($client)->sent(now()->toDateTimeString())->create();

    expect(updateService()->entryFor($client)->urgency)->toBe(UpdateUrgency::OnTrack);
});

test('sem hora-alvo o compromisso cai ao meio-dia, e isso não muda a urgência', function () {
    $this->travelTo('2026-08-07 12:00:00'); // 09:00 no fuso de negócio.

    $client = Client::factory()->create([
        'update_day' => MorningRitual::businessNow()->dayOfWeekIso,
        'update_time' => null,
    ]);

    $entry = updateService()->entryFor($client);

    expect($entry->urgency)->toBe(UpdateUrgency::DueToday)
        ->and($entry->dueAt->format('H:i'))->toBe('12:00');
});

test('uma entrega devolvida para ajustes continua no bloco Entregue, dizendo isso', function () {
    [$client, $project] = clientWithProject();

    $bounced = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Portal de faturas',
        'status' => ActivityStatus::Doing,
    ]);
    withSpecHistory($bounced, [
        [ActivityStatus::AwaitingApproval, '2026-07-20 09:00:00'],
        [ActivityStatus::Doing, '2026-07-22 09:00:00'],
        [ActivityStatus::AwaitingValidation, '2026-08-04 09:00:00'],
        // Reprovada na validação: voltou para a bancada dentro da mesma
        // janela em que foi entregue.
        [ActivityStatus::Doing, '2026-08-06 09:00:00'],
    ]);

    $blocks = collect(updateService()->blocks($client))->keyBy('key');

    // A entrega aconteceu, o cliente a viu, e omiti-la sugeriria que ela
    // nunca saiu — o bloco a mantém e conta o desfecho.
    expect($blocks['entregue']['items'])->toHaveCount(1)
        ->and($blocks['entregue']['items'][0]['detail'])->toBe('entregue e devolvida para ajustes')
        // E o trabalho reaparece onde ele de fato está agora.
        ->and(collect($blocks['em_andamento']['items'])->pluck('id')->all())->toBe([$bounced->id]);
});

test('o banco não deixa dois rascunhos abertos para o mesmo cliente', function () {
    [$client] = clientWithProject();

    updateService()->generate($client);

    // A corrida que a transação evita, forçada à mão: se o índice único não
    // estivesse lá, este segundo rascunho passaria e a fila cobraria um
    // update que ninguém mais veria.
    ClientUpdate::factory()->for($client)->create();
})->throws(QueryException::class);

test('gerar de novo reaproveita o rascunho aberto em vez de abrir um segundo', function () {
    [$client] = clientWithProject();

    updateService()->generate($client);
    updateService()->generate($client->fresh());

    expect(ClientUpdate::query()->draft()->where('client_id', $client->id)->count())->toBe(1);
});

test('a fila não hidrata o histórico inteiro de cada cliente', function () {
    [$client, $project] = clientWithProject();
    [$other, $otherProject] = clientWithProject();

    ClientUpdate::factory()->for($client)->count(20)->sent('2026-07-20 09:00:00')->create();
    updateService()->generate($client);

    // Com evento de verdade nos dois clientes, um de cada tipo: é justamente
    // quando há candidato que uma varredura com relações carregadas cobraria
    // as consultas extras dos eager loads.
    $spec = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($spec, [[ActivityStatus::AwaitingValidation, '2026-08-06 09:00:00']]);

    Activity::factory()->issue()->doing()->create([
        'project_id' => $otherProject->id,
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada.',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $queue = updateService()->queue();

    $mine = $queue->first(fn (ClientUpdateQueueEntry $entry): bool => $entry->client->is($client));
    $theirs = $queue->first(fn (ClientUpdateQueueEntry $entry): bool => $entry->client->is($other));

    // Quatro consultas: os clientes, o último envio de cada um, o rascunho de
    // cada um e a varredura de gatilhos por evento (issue #150) — uma para a
    // fila inteira, com ou sem candidatos, e não uma por cliente. A badge da
    // sidebar roda isto em toda página, então o custo não pode crescer nem
    // com o histórico nem com o número de eventos no quadro.
    expect(DB::getQueryLog())->toHaveCount(4)
        ->and($mine->lastSentAt)->not->toBeNull()
        ->and($mine->hasDraft())->toBeTrue()
        ->and($mine->triggers)->toBe([UpdateTrigger::DeliveryAwaitingValidation])
        ->and($theirs->triggers)->toBe([UpdateTrigger::Emergency]);

    DB::disableQueryLog();
});

test('uma edição só de formatação também conta como edição manual', function () {
    [$client] = clientWithProject();

    $draft = updateService()->generate($client);

    // Em Markdown o espaço é conteúdo: uma linha em branco a mais separa
    // parágrafos, e descartá-la sem perguntar seria descartar uma edição.
    $draft->update(['content' => $draft->content."\n"]);

    expect($draft->hasManualEdits())->toBeTrue();

    updateService()->generate($client->fresh());
})->throws(UpdateDraftHasManualEditsException::class);

test('o histórico pagina em vez de sumir depois do décimo segundo envio', function () {
    [$client] = clientWithProject();

    foreach (range(1, 15) as $week) {
        ClientUpdate::factory()->for($client)->sent(now()->subWeeks($week)->toDateTimeString())->create([
            'content' => "Update da semana {$week}",
        ]);
    }

    expect(updateService()->sentCount($client))->toBe(15)
        ->and(updateService()->history($client))->toHaveCount(12)
        ->and(updateService()->history($client, 24))->toHaveCount(15)
        // E o mais antigo continua alcançável, que é o ponto do histórico.
        ->and(updateService()->history($client, 24)->last()->content)->toBe('Update da semana 15');
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

/*
|--------------------------------------------------------------------------
| Gatilhos de update por evento (issue #150)
|--------------------------------------------------------------------------
|
| Nada aqui é persistido: o gatilho é uma pergunta refeita a cada leitura da
| fila, contra a janela desde o último envio. É por isso que "enviar apaga o
| gatilho" não precisa de nenhuma marcação de lido — enviar abre uma janela
| nova, e a pergunta passa a ser feita sobre outros dias.
|
*/

test('uma spec entregue para validação na janela põe o cliente em evento', function () {
    [$client, $project] = clientWithProject();

    $spec = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Portal de faturas',
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($spec, [
        [ActivityStatus::AwaitingApproval, '2026-07-20 09:00:00'],
        [ActivityStatus::AwaitingValidation, '2026-08-05 09:00:00'],
    ]);

    $entry = updateService()->entryFor($client->fresh());

    expect($entry->urgency)->toBe(UpdateUrgency::Event)
        ->and($entry->triggers)->toBe([UpdateTrigger::DeliveryAwaitingValidation])
        ->and($entry->triggers[0]->label())->toBe('Entrega aguardando validação')
        // A cadência continua sendo a cadência: o evento promove a categoria,
        // não apaga o relógio.
        ->and($entry->cadence)->not->toBe(UpdateUrgency::Event);
});

test('uma entrega anterior à janela não dispara gatilho', function () {
    [$client, $project] = clientWithProject();

    ClientUpdate::factory()->for($client)->sent('2026-08-06 10:00:00')->create();

    $spec = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    // Entregue antes do último envio: o cliente já foi avisado.
    withSpecHistory($spec, [[ActivityStatus::AwaitingValidation, '2026-08-04 09:00:00']]);

    expect(updateService()->entryFor($client->fresh())->triggers)->toBe([]);
});

test('uma Emergência ativa de item do cliente põe o cliente em evento', function () {
    [$client, $project] = clientWithProject();

    $epic = Activity::factory()->epic()->create(['project_id' => $project->id]);

    // Numa filha de propósito: o gatilho fala de *item* do cliente, não de
    // nível spec — uma Emergência é uma Emergência onde quer que ela esteja.
    Activity::factory()->issue()->doing()->forParent($epic)->create([
        'project_id' => $project->id,
        'title' => 'Servidor fora do ar',
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada desde as 8h.',
    ]);

    $entry = updateService()->entryFor($client->fresh());

    expect($entry->urgency)->toBe(UpdateUrgency::Event)
        ->and($entry->triggers)->toBe([UpdateTrigger::Emergency])
        ->and($entry->triggers[0]->label())->toBe('Emergência');
});

test('uma Emergência concluída na janela com motivo põe o cliente em evento', function () {
    [$client, $project] = clientWithProject();

    $emergency = Activity::factory()->issue()->done()->create([
        'project_id' => $project->id,
        'title' => 'Servidor fora do ar',
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada desde as 8h.',
    ]);
    withSpecHistory($emergency, [
        [ActivityStatus::Doing, '2026-08-04 09:00:00'],
        [ActivityStatus::Done, '2026-08-06 09:00:00'],
    ]);

    expect(updateService()->entryFor($client->fresh())->triggers)->toBe([UpdateTrigger::Emergency]);
});

test('uma Emergência concluída antes da janela não dispara gatilho', function () {
    [$client, $project] = clientWithProject();

    $emergency = Activity::factory()->issue()->done()->create([
        'project_id' => $project->id,
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada.',
    ]);
    withSpecHistory($emergency, [[ActivityStatus::Done, '2026-07-20 09:00:00']]);

    expect(updateService()->entryFor($client->fresh())->triggers)->toBe([]);
});

test('uma Emergência concluída sem motivo não dispara gatilho', function () {
    [$client, $project] = clientWithProject();

    $emergency = Activity::factory()->issue()->done()->create([
        'project_id' => $project->id,
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada.',
    ]);
    withSpecHistory($emergency, [[ActivityStatus::Done, '2026-08-06 09:00:00']]);

    // Sem motivo não há o que contar ao cliente além de "houve um incêndio".
    // Só se chega aqui por baixo do modelo (o observer exige o motivo), que é
    // exatamente a forma dos registros legados da issue #143.
    $emergency->forceFill(['emergency_reason' => null])->saveQuietly();

    expect(updateService()->entryFor($client->fresh())->triggers)->toBe([]);
});

test('uma Emergência ativa mais velha que a janela já foi contada e não dispara de novo', function () {
    [$client, $project] = clientWithProject();

    ClientUpdate::factory()->for($client)->sent('2026-08-06 10:00:00')->create();

    $emergency = Activity::factory()->issue()->doing()->create([
        'project_id' => $project->id,
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada.',
    ]);
    // `emergency_since` só é carimbado pelo observer (issue #143) — envelhecer
    // a classificação é escrever a coluna por baixo do modelo.
    $emergency->forceFill(['emergency_since' => '2026-08-01 09:00:00'])->saveQuietly();

    // O update de 06/08 já falou dela; continuar cobrando manteria o cliente
    // em "evento" enquanto o fogo durasse.
    expect(updateService()->entryFor($client->fresh())->triggers)->toBe([]);

    // Concluí-la, porém, é notícia nova.
    withSpecHistory($emergency, [[ActivityStatus::Done, now()->toDateTimeString()]]);
    $emergency->forceFill(['status' => ActivityStatus::Done])->saveQuietly();

    expect(updateService()->entryFor($client->fresh())->triggers)->toBe([UpdateTrigger::Emergency]);
});

test('a categoria evento ordena acima de atrasado e conta na badge', function () {
    $today = MorningRitual::businessNow();

    Client::factory()->create([
        'name' => 'Atrasado',
        'update_day' => $today->copy()->subDays(2)->dayOfWeekIso,
        'update_time' => '09:00',
    ]);

    // Em dia pelo relógio: recebeu update hoje mesmo. O que o põe no topo da
    // fila é o evento, não a cadência.
    [$eventful, $project] = clientWithProject([
        'name' => 'Com evento',
        'update_day' => $today->dayOfWeekIso,
        'update_time' => '09:00',
    ]);
    ClientUpdate::factory()->for($eventful)->sent(now()->subHours(3)->toDateTimeString())->create();

    $spec = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($spec, [[ActivityStatus::AwaitingValidation, now()->subHour()->toDateTimeString()]]);

    $onTrack = Client::factory()->create([
        'name' => 'Em dia',
        'update_day' => $today->dayOfWeekIso,
        'update_time' => '09:00',
    ]);
    ClientUpdate::factory()->for($onTrack)->sent(now()->toDateTimeString())->create();

    $queue = updateService()->queue();

    expect($queue->pluck('client.name')->all())->toBe(['Com evento', 'Atrasado', 'Em dia'])
        ->and($queue[0]->urgency)->toBe(UpdateUrgency::Event)
        ->and($queue[0]->cadence)->toBe(UpdateUrgency::OnTrack)
        ->and($queue[1]->urgency)->toBe(UpdateUrgency::Overdue)
        // A badge soma os dois: o atrasado pelo relógio e o do evento, que a
        // cadência sozinha não cobraria.
        ->and(updateService()->dueCount())->toBe(2);
});

test('enviar o update apaga o gatilho, porque abre uma janela nova', function () {
    [$client, $project] = clientWithProject();

    $spec = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($spec, [[ActivityStatus::AwaitingValidation, '2026-08-05 09:00:00']]);

    expect(updateService()->entryFor($client->fresh())->urgency)->toBe(UpdateUrgency::Event);

    updateService()->markSent(updateService()->generate($client->fresh()));

    $entry = updateService()->entryFor($client->fresh());

    expect($entry->triggers)->toBe([])
        ->and($entry->urgency)->toBe(UpdateUrgency::OnTrack);
});

test('um evento resolvido antes do envio mantém o gatilho até enviar', function () {
    [$client, $project] = clientWithProject();

    $spec = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::Done,
    ]);
    // Entregue e já validada dentro da mesma janela: o evento aconteceu, e
    // isso não deixa de ser verdade porque ele terminou bem.
    withSpecHistory($spec, [
        [ActivityStatus::AwaitingValidation, '2026-08-05 09:00:00'],
        [ActivityStatus::Done, '2026-08-06 09:00:00'],
    ]);

    expect(updateService()->entryFor($client->fresh())->triggers)
        ->toBe([UpdateTrigger::DeliveryAwaitingValidation]);
});

test('furo aceito: uma Emergência rebaixada antes do envio some do radar', function () {
    [$client, $project] = clientWithProject();

    $emergency = Activity::factory()->issue()->doing()->create([
        'project_id' => $project->id,
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada.',
    ]);

    expect(updateService()->entryFor($client->fresh())->triggers)->toBe([UpdateTrigger::Emergency]);

    // Rebaixar apaga classe, motivo e data (issue #143). Sem persistência de
    // gatilho, não sobra nada no estado que prove que a Emergência existiu —
    // o preço documentado de não ter tabela nova (issue #150).
    $emergency->update(['service_class' => ServiceClass::Standard]);

    expect(updateService()->entryFor($client->fresh())->triggers)->toBe([]);
});

test('um evento no mesmo segundo do envio não sobrevive ao envio', function () {
    [$client, $project] = clientWithProject();

    // `sent_at`, `changed_at` e `emergency_since` têm precisão de segundo, e
    // enviar logo depois de mexer no quadro é o gesto normal — o empate na
    // fronteira é alcançável de fato, não teórico.
    $sentAt = '2026-08-06 10:00:00';
    ClientUpdate::factory()->for($client)->sent($sentAt)->create();

    $spec = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($spec, [[ActivityStatus::AwaitingValidation, $sentAt]]);

    $emergency = Activity::factory()->issue()->doing()->create([
        'project_id' => $project->id,
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada.',
    ]);
    $emergency->forceFill(['emergency_since' => $sentAt])->saveQuietly();

    // O que aconteceu no instante do envio saiu no texto que acabou de ir.
    expect(updateService()->entryFor($client->fresh())->triggers)->toBe([]);
});

test('cada cliente é medido pela própria janela, numa varredura só', function () {
    $today = MorningRitual::businessNow();

    // Janela recente: recebeu update hoje de manhã, e está em dia.
    [$recent, $recentProject] = clientWithProject([
        'name' => 'Janela recente',
        'update_day' => $today->dayOfWeekIso,
        'update_time' => '09:00',
    ]);
    ClientUpdate::factory()->for($recent)->sent('2026-08-07 08:00:00')->create();

    // Janela antiga: o último envio foi há cinco dias, e o relógio já cobra.
    [$old, $oldProject] = clientWithProject([
        'name' => 'Janela antiga',
        'update_day' => $today->copy()->subDays(2)->dayOfWeekIso,
        'update_time' => '09:00',
    ]);
    ClientUpdate::factory()->for($old)->sent('2026-08-02 10:00:00')->create();

    // A entrega do cliente de janela recente caiu *entre* as duas fronteiras:
    // dentro da janela do outro, fora da dele. Medir todo mundo pela janela
    // mais antiga — o piso da varredura em lote — o poria em evento por um
    // update que ele já recebeu.
    $mine = Activity::factory()->epic()->create([
        'project_id' => $recentProject->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($mine, [[ActivityStatus::AwaitingValidation, '2026-08-04 09:00:00']]);

    $theirs = Activity::factory()->epic()->create([
        'project_id' => $oldProject->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($theirs, [[ActivityStatus::AwaitingValidation, '2026-08-05 09:00:00']]);

    // Uma leitura da fila, os dois clientes na mesma varredura.
    $queue = updateService()->queue();

    expect($queue->pluck('client.name')->all())->toBe(['Janela antiga', 'Janela recente'])
        ->and($queue[0]->triggers)->toBe([UpdateTrigger::DeliveryAwaitingValidation])
        ->and($queue[0]->urgency)->toBe(UpdateUrgency::Event)
        ->and($queue[0]->cadence)->toBe(UpdateUrgency::Overdue)
        ->and($queue[1]->triggers)->toBe([])
        ->and($queue[1]->urgency)->toBe(UpdateUrgency::OnTrack)
        ->and(updateService()->dueCount())->toBe(1);
});

test('os dois gatilhos convivem na mesma linha, na ordem dos chips', function () {
    [$client, $project] = clientWithProject();

    $spec = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($spec, [[ActivityStatus::AwaitingValidation, '2026-08-05 09:00:00']]);

    Activity::factory()->issue()->doing()->create([
        'project_id' => $project->id,
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada.',
    ]);

    // A Emergência primeiro: quem bate o olho na fila lê antes o que grita
    // mais alto.
    expect(updateService()->entryFor($client->fresh())->triggers)
        ->toBe([UpdateTrigger::Emergency, UpdateTrigger::DeliveryAwaitingValidation]);
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
