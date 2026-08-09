<?php

use App\Enums\ActivityStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\GenerateClientUpdateTool;
use App\Mcp\Tools\GetUpdateQueueTool;
use App\Mcp\Tools\MarkUpdateSentTool;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientUpdate;
use App\Models\MorningRitual;
use App\Models\Project;
use App\Services\ClientUpdateService;

/**
 * A costura MCP do update semanal (issue #149).
 *
 * As três tools não têm lógica própria: consomem
 * {@see ClientUpdateService}, o mesmo serviço que a página Updates consome.
 * O que estes testes protegem é justamente isso — que o rascunho gerado por
 * um agente seja o rascunho que aparece na tela, e que o "não" seja o
 * mesmo "não".
 */

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function updateToolPayload(string $tool, array $arguments = []): array
{
    $response = SoloBoardServer::tool($tool, $arguments);

    $content = (new ReflectionMethod($response, 'content'))->invoke($response);

    return json_decode($content[0], true, flags: JSON_THROW_ON_ERROR);
}

function updateToolText(string $tool, array $arguments = []): string
{
    $response = SoloBoardServer::tool($tool, $arguments);

    $content = (new ReflectionMethod($response, 'content'))->invoke($response);

    return $content[0];
}

beforeEach(function () {
    $this->travelTo('2026-08-07 15:00:00');
});

test('get-update-queue devolve a fila na ordem da página e conta os devidos', function () {
    $today = MorningRitual::businessNow();

    Client::factory()->create([
        'name' => 'Atrasada ME',
        'slug' => 'atrasada',
        'update_day' => $today->copy()->subDays(2)->dayOfWeekIso,
        'update_time' => '09:00',
    ]);

    $onTrack = Client::factory()->create([
        'name' => 'Em dia SA',
        'slug' => 'em-dia',
        'update_day' => $today->dayOfWeekIso,
        'update_time' => '09:00',
    ]);
    ClientUpdate::factory()->for($onTrack)->sent(now()->toDateTimeString())->create();

    Client::factory()->archived()->create(['name' => 'Arquivada']);

    $payload = updateToolPayload(GetUpdateQueueTool::class);

    expect($payload['queue'])->toHaveCount(2)
        ->and($payload['queue'][0]['client_slug'])->toBe('atrasada')
        ->and($payload['queue'][0]['urgency'])->toBe('overdue')
        ->and($payload['queue'][0]['days_late'])->toBe(2)
        ->and($payload['queue'][1]['urgency'])->toBe('on_track')
        ->and($payload['due_count'])->toBe(1)
        ->and($payload['total_active_clients'])->toBe(2);

    // A ordem é a mesma que o serviço entrega à página.
    expect(collect($payload['queue'])->pluck('client_slug')->all())
        ->toBe(app(ClientUpdateService::class)->queue()->pluck('client.slug')->all());
});

test('get-update-queue pode devolver só os devidos', function () {
    $today = MorningRitual::businessNow();

    Client::factory()->create(['slug' => 'devida', 'update_day' => $today->copy()->subDay()->dayOfWeekIso, 'update_time' => '09:00']);

    $onTrack = Client::factory()->create(['slug' => 'em-dia', 'update_day' => $today->dayOfWeekIso, 'update_time' => '09:00']);
    ClientUpdate::factory()->for($onTrack)->sent(now()->toDateTimeString())->create();

    $payload = updateToolPayload(GetUpdateQueueTool::class, ['only_due' => true]);

    expect($payload['queue'])->toHaveCount(1)
        ->and($payload['queue'][0]['client_slug'])->toBe('devida')
        ->and($payload['total_active_clients'])->toBe(2);
});

test('generate-client-update persiste o mesmo rascunho que a UI', function () {
    $client = Client::factory()->create(['slug' => 'acme']);
    $project = Project::factory()->create(['client_id' => $client->id]);

    $waiting = Activity::factory()->epic()->awaitingApproval()->create([
        'project_id' => $project->id,
        'title' => 'Spec do checkout',
    ]);
    withSpecHistory($waiting, [[ActivityStatus::AwaitingApproval, now()->subDay()->toDateTimeString()]]);

    $payload = updateToolPayload(GenerateClientUpdateTool::class, ['client_slug' => 'acme']);

    $draft = $client->fresh()->draftUpdate();

    expect($payload['generated'])->toBeTrue()
        ->and($payload['draft_id'])->toBe($draft->id)
        ->and($payload['sent_at'])->toBeNull()
        ->and($payload['content'])->toBe($draft->content)
        ->and($payload['content'])->toContain('Spec do checkout')
        ->and(collect($payload['blocks'])->pluck('key')->all())->toBe(['esperando_voce']);
});

test('generate-client-update recusa descartar edição manual sem force', function () {
    $client = Client::factory()->create(['slug' => 'acme']);
    Project::factory()->create(['client_id' => $client->id]);

    $draft = app(ClientUpdateService::class)->generate($client);
    $draft->update(['content' => 'Texto escrito na mão.']);

    $response = SoloBoardServer::tool(GenerateClientUpdateTool::class, ['client_slug' => 'acme']);
    $response->assertHasErrors();

    expect(updateToolText(GenerateClientUpdateTool::class, ['client_slug' => 'acme']))
        ->toContain('draft_has_manual_edits');

    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');

    $payload = updateToolPayload(GenerateClientUpdateTool::class, ['client_slug' => 'acme', 'force' => true]);

    expect($payload['generated'])->toBeTrue()
        ->and($client->fresh()->draftUpdate()->content)->not->toBe('Texto escrito na mão.');
});

test('generate-client-update exige um cliente', function () {
    SoloBoardServer::tool(GenerateClientUpdateTool::class, [])->assertHasErrors();
});

test('mark-update-sent grava a data e o próximo rascunho cobre desde aí', function () {
    $client = Client::factory()->create(['slug' => 'acme']);
    Project::factory()->create(['client_id' => $client->id]);

    $draft = app(ClientUpdateService::class)->generate($client);

    $payload = updateToolPayload(MarkUpdateSentTool::class, ['draft_id' => $draft->id]);

    expect($payload['sent'])->toBeTrue()
        ->and($payload['update_id'])->toBe($draft->id)
        ->and($payload['sent_at'])->toBe(now()->toDateTimeString())
        ->and($payload['next_window_starts_at'])->toBe(now()->toDateTimeString());

    expect($client->fresh()->draftUpdate())->toBeNull()
        ->and($client->fresh()->lastSentUpdate()->id)->toBe($draft->id);
});

test('mark-update-sent recusa sem um rascunho existente', function () {
    SoloBoardServer::tool(MarkUpdateSentTool::class, [])->assertHasErrors();

    SoloBoardServer::tool(MarkUpdateSentTool::class, ['draft_id' => 987654])->assertHasErrors();
});

test('mark-update-sent recusa um update já enviado', function () {
    $client = Client::factory()->create();
    $sent = ClientUpdate::factory()->for($client)->sent()->create();

    $response = SoloBoardServer::tool(MarkUpdateSentTool::class, ['draft_id' => $sent->id]);
    $response->assertHasErrors();

    expect(updateToolText(MarkUpdateSentTool::class, ['draft_id' => $sent->id]))
        ->toContain('already_sent');
});

test('as três tools estão registradas no servidor', function () {
    $tools = (new ReflectionClass(SoloBoardServer::class))->getDefaultProperties()['tools'];

    expect($tools)->toContain(GetUpdateQueueTool::class)
        ->toContain(GenerateClientUpdateTool::class)
        ->toContain(MarkUpdateSentTool::class);
});
