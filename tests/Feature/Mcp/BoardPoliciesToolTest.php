<?php

use App\Enums\PolicyKey;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\GetBoardPoliciesTool;
use App\Models\Activity;
use App\Models\Client;
use App\Models\PolicyVersion;

/**
 * A costura MCP das políticas do quadro (issue #154): as mesmas três
 * partes que o painel do Kanban renderiza.
 *
 * @return array<string, mixed>
 */
function boardPoliciesPayload(): array
{
    $response = SoloBoardServer::tool(GetBoardPoliciesTool::class, []);

    $response->assertOk();

    $content = (new ReflectionMethod($response, 'content'))->invoke($response);

    return json_decode($content[0], true, flags: JSON_THROW_ON_ERROR);
}

test('get-board-policies devolve as mecânicas computadas do estado real', function () {
    config()->set('soloboard.wip_limit_doing', 4);
    config()->set('soloboard.fixed_date_risk_days', 9);

    Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create(['title' => 'Restaurar API']);

    $mechanics = collect(boardPoliciesPayload()['mechanics'])->keyBy('key');

    expect($mechanics->keys()->all())
        ->toBe(['wip_limit_doing', 'single_emergency', 'pull_order', 'risk_window'])
        ->and($mechanics['wip_limit_doing']['statement'])->toContain('No máximo 4 itens')
        ->and($mechanics['single_emergency']['current'])->toBe('Ativa: Restaurar API')
        ->and($mechanics['pull_order']['statement'])->toContain('1. Emergência')
        ->and($mechanics['risk_window']['statement'])->toContain('9 dias')
        ->and($mechanics['risk_window']['current'])->toBe('Fonte: config');
});

test('get-board-policies devolve o texto vigente das três seções, com nota e contagem', function () {
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'v1', 'Padrão inicial do método');
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'v2', 'Passei a exigir teste');

    $sections = collect(boardPoliciesPayload()['sections'])->keyBy('key');

    expect($sections->keys()->all())
        ->toBe(['definition_of_done', 'definition_of_ready', 'working_agreements'])
        ->and($sections['definition_of_done']['body'])->toBe('v2')
        ->and($sections['definition_of_done']['note'])->toBe('Passei a exigir teste')
        ->and($sections['definition_of_done']['versions_count'])->toBe(2)
        ->and($sections['definition_of_done']['updated_at'])->not->toBeNull()
        // Nunca escrita é body nulo, não string vazia.
        ->and($sections['working_agreements']['body'])->toBeNull()
        ->and($sections['working_agreements']['versions_count'])->toBe(0);
});

test('get-board-policies devolve os acordos de resposta e quem não tem', function () {
    Client::factory()->create([
        'name' => 'Acme',
        'is_active' => true,
        'response_agreement' => 'Respondo em até 1 dia útil',
    ]);
    Client::factory()->create(['name' => 'Sem acordo', 'is_active' => true, 'response_agreement' => null]);
    Client::factory()->create(['name' => 'Inativo', 'is_active' => false, 'response_agreement' => null]);

    $payload = boardPoliciesPayload();

    expect($payload['response_agreements'])->toHaveCount(1)
        ->and($payload['response_agreements'][0]['name'])->toBe('Acme')
        ->and($payload['response_agreements'][0]['agreement'])->toBe('Respondo em até 1 dia útil')
        ->and($payload['clients_without_agreement'])->toHaveCount(1)
        ->and($payload['clients_without_agreement'][0]['name'])->toBe('Sem acordo');
});

test('as instruções do servidor descrevem as políticas do quadro', function () {
    $instructions = (new ReflectionProperty(SoloBoardServer::class, 'instructions'))
        ->getDefaultValue();

    // Um agente lê as instruções antes da descrição da tool: as duas não
    // podem divergir sobre o que o painel publica.
    expect($instructions)
        ->toContain('get-board-policies')
        ->toContain('definition_of_done')
        ->toContain('definition_of_ready')
        ->toContain('working_agreements')
        ->toContain('append-only')
        ->toContain('read-only');
});
