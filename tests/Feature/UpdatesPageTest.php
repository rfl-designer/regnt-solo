<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientUpdate;
use App\Models\MorningRitual;
use App\Models\Project;
use App\Models\User;
use App\Services\ClientUpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * A página Updates (issue #149): a fila, o rascunho com autosave, os dois
 * atos separados (copiar / marcar como enviado) e o histórico.
 */
beforeEach(function () {
    $this->travelTo('2026-08-07 15:00:00');
    $this->actingAs(User::factory()->create());
});

/**
 * @return array{0: Client, 1: Project}
 */
function updatesPageClient(array $attributes = []): array
{
    $client = Client::factory()->create($attributes);
    $project = Project::factory()->create(['client_id' => $client->id]);

    return [$client, $project];
}

test('a página carrega e ordena a fila por urgência', function () {
    $today = MorningRitual::businessNow();

    // Em dia porque já recebeu update depois do compromisso desta rodada —
    // um cliente que nunca recebeu nada está sempre devido.
    $onTrack = Client::factory()->create([
        'name' => 'Em dia SA',
        'update_day' => $today->dayOfWeekIso,
        'update_time' => '09:00',
    ]);
    ClientUpdate::factory()->for($onTrack)->sent(now()->toDateTimeString())->create();

    Client::factory()->create([
        'name' => 'Atrasada ME',
        'update_day' => $today->copy()->subDays(3)->dayOfWeekIso,
        'update_time' => '09:00',
    ]);

    Livewire::test('pages::updates')
        ->assertOk()
        ->assertSeeInOrder(['Atrasada ME', 'Em dia SA'])
        ->assertSee('Atrasado');
});

test('um gatilho por evento sobe o cliente na fila, com o chip do motivo', function () {
    $today = MorningRitual::businessNow();

    Client::factory()->create([
        'name' => 'Atrasada ME',
        'update_day' => $today->copy()->subDays(3)->dayOfWeekIso,
        'update_time' => '09:00',
    ]);

    // Em dia pelo relógio: recebeu update há três horas. O que a põe no topo
    // é a entrega esperando validação desde então (issue #150).
    [$eventful, $project] = updatesPageClient([
        'name' => 'Com evento SA',
        'update_day' => $today->dayOfWeekIso,
        'update_time' => '09:00',
    ]);
    ClientUpdate::factory()->for($eventful)->sent(now()->subHours(3)->toDateTimeString())->create();

    $spec = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingValidation,
    ]);
    withSpecHistory($spec, [[ActivityStatus::AwaitingValidation, now()->subHour()->toDateTimeString()]]);

    Livewire::test('pages::updates')
        ->assertOk()
        ->assertSeeInOrder(['Com evento SA', 'Atrasada ME'])
        ->assertSee('Evento')
        ->assertSee('Entrega aguardando validação')
        ->assertSeeHtml('data-test="queue-trigger-delivery_awaiting_validation"')
        // A chave é por cliente *e* por gatilho: sem ela, o morph do Livewire
        // reaproveitaria por posição o chip da linha anterior quando um envio
        // apaga o gatilho de um cliente e não o do outro.
        ->assertSeeHtml('wire:key="queue-trigger-'.$eventful->id.'-delivery_awaiting_validation"');
});

test('a badge da sidebar conta um cliente que só está devido por evento', function () {
    $today = MorningRitual::businessNow();

    // Cadência em dia: sem o gatilho, este cliente não apareceria na badge.
    [$client, $project] = updatesPageClient([
        'update_day' => $today->dayOfWeekIso,
        'update_time' => '09:00',
    ]);
    ClientUpdate::factory()->for($client)->sent(now()->subHours(3)->toDateTimeString())->create();

    Livewire::test('updates-badge')->assertDontSeeHtml('data-test="updates-due-badge"');

    Activity::factory()->issue()->doing()->create([
        'project_id' => $project->id,
        'title' => 'Servidor fora do ar',
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Produção parada desde as 8h.',
    ]);

    Livewire::test('updates-badge')
        ->assertSee('1')
        ->assertSeeHtml('data-test="updates-due-badge"');
});

test('a badge da sidebar conta os updates devidos e some quando não há nenhum', function () {
    $today = MorningRitual::businessNow();

    $due = Client::factory()->create([
        'update_day' => $today->copy()->subDay()->dayOfWeekIso,
        'update_time' => '09:00',
    ]);

    Livewire::test('updates-badge')
        ->assertSee('1')
        ->assertSeeHtml('data-test="updates-due-badge"');

    app(ClientUpdateService::class)->markSent(app(ClientUpdateService::class)->generate($due));

    Livewire::test('updates-badge')
        ->assertDontSeeHtml('data-test="updates-due-badge"');
});

test('gerar monta o rascunho do estado real e o persiste', function () {
    [$client, $project] = updatesPageClient(['slug' => 'acme']);

    $waiting = Activity::factory()->epic()->awaitingApproval()->create([
        'project_id' => $project->id,
        'title' => 'Spec do checkout',
    ]);
    withSpecHistory($waiting, [[ActivityStatus::AwaitingApproval, now()->subDay()->toDateTimeString()]]);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->call('generate')
        ->assertSet('content', fn (string $content): bool => str_contains($content, 'Spec do checkout'));

    expect($client->draftUpdate())->not->toBeNull()
        ->and($client->draftUpdate()->content)->toContain('**Esperando você**');
});

test('o editor salva sozinho o que foi digitado', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    app(ClientUpdateService::class)->generate($client);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Oi! Texto meu.');

    expect($client->fresh()->draftUpdate()->content)->toBe('Oi! Texto meu.')
        ->and($client->fresh()->draftUpdate()->hasManualEdits())->toBeTrue();
});

test('regenerar pede confirmação quando há edição manual', function () {
    [$client, $project] = updatesPageClient(['slug' => 'acme']);

    app(ClientUpdateService::class)->generate($client);

    $component = Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        ->call('requestRegenerate')
        ->assertSet('showRegenerateConfirm', true);

    // Enquanto não confirma, o texto do usuário continua lá.
    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');

    $component->call('regenerate')
        ->assertSet('showRegenerateConfirm', false);

    expect($client->fresh()->draftUpdate()->content)->not->toBe('Texto escrito na mão.')
        ->and($client->fresh()->draftUpdate()->hasManualEdits())->toBeFalse();
});

test('regenerar não pede confirmação quando o rascunho está intocado', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    app(ClientUpdateService::class)->generate($client);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->call('requestRegenerate')
        ->assertSet('showRegenerateConfirm', false);
});

test('chamar regenerate direto não descarta a edição — pergunta, como o botão', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    app(ClientUpdateService::class)->generate($client);

    // O modal é a pergunta, não a autorização. Uma chamada Livewire direta
    // pula o modal, e é justamente por isso que o `force` não pode sair dele.
    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        ->call('regenerate')
        ->assertSet('showRegenerateConfirm', true)
        ->assertSet('content', 'Texto escrito na mão.');

    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');
});

test('forjar o estado do modal não autoriza o descarte', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    app(ClientUpdateService::class)->generate($client);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        // O que o browser consegue mudar: o booleano que abre o modal.
        ->set('showRegenerateConfirm', true)
        ->call('regenerate');

    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');

    // O que autoriza de fato é uma propriedade travada, que o browser não
    // alcança — tentar mudá-la é um erro, não uma permissão.
    expect(fn () => Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('regenerateConfirmedFor', $client->fresh()->draftUpdate()->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('chamar generate direto num rascunho editado também pede confirmação', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    app(ClientUpdateService::class)->generate($client);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        ->call('generate')
        ->assertSet('showRegenerateConfirm', true);

    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');
});

test('o histórico carrega mais quando passa de doze envios', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    foreach (range(1, 15) as $week) {
        ClientUpdate::factory()->for($client)->sent(now()->subWeeks($week)->toDateTimeString())->create([
            'content' => "Update da semana {$week}",
        ]);
    }

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->assertSee('Update da semana 12')
        ->assertDontSee('Update da semana 15')
        ->assertSeeHtml('data-test="load-more-history"')
        ->call('loadMoreHistory')
        ->assertSee('Update da semana 15')
        ->assertDontSeeHtml('data-test="load-more-history"');
});

test('copiar leva o texto ao clipboard sem gravar nada', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    app(ClientUpdateService::class)->generate($client);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->call('copy')
        ->assertDispatched('copy-to-clipboard');

    expect($client->fresh()->draftUpdate())->not->toBeNull()
        ->and($client->fresh()->draftUpdate()->sent_at)->toBeNull()
        ->and(app(ClientUpdateService::class)->history($client->fresh()))->toBeEmpty();
});

test('marcar como enviado grava a data, zera o relógio e manda o rascunho para o histórico', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    app(ClientUpdateService::class)->generate($client);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->call('markSent')
        ->assertSet('content', '')
        ->assertDispatched('client-update-sent');

    $client = $client->fresh();

    expect($client->draftUpdate())->toBeNull()
        ->and($client->lastSentUpdate()->sent_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and(app(ClientUpdateService::class)->windowStart($client)->toDateTimeString())
        ->toBe(now()->toDateTimeString());
});

test('o histórico do cliente aparece na página', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    ClientUpdate::factory()->for($client)->sent('2026-08-01 09:00:00')->create([
        'content' => 'Update da semana passada',
    ]);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->assertSee('Update da semana passada')
        ->assertSee('01/08/2026');
});

test('sem cliente escolhido a página convida a escolher um', function () {
    updatesPageClient();

    Livewire::test('pages::updates')
        ->assertSee('Escolha um cliente')
        ->assertDontSeeHtml('data-test="update-editor"');
});

test('escolher um cliente carrega o rascunho dele no editor', function () {
    [$client] = updatesPageClient(['slug' => 'acme']);

    ClientUpdate::factory()->for($client)->create(['content' => 'Rascunho da Acme']);

    Livewire::test('pages::updates')
        ->call('select', 'acme')
        ->assertSet('client', 'acme')
        ->assertSet('content', 'Rascunho da Acme');
});

/**
 * Refinar com AI (issue #151): a AI é revisora de texto do rascunho, atrás da
 * feature flag, e nunca escreve direto no editor.
 */
function updatesPageAiEnabled(): void
{
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);
}

test('sem a flag de AI a página não oferece refinar, e o fluxo determinístico segue inteiro', function () {
    config(['soloboard.ai_enabled' => false, 'soloboard.ai_api_key' => 'test-key']);

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->assertDontSee('Refinar com AI')
        ->assertDontSeeHtml('data-test="refine-update-with-ai"')
        // A barra determinística continua completa: regerar, copiar, enviar.
        ->assertSeeHtml('data-test="regenerate-update"')
        ->assertSeeHtml('data-test="copy-update"')
        ->assertSeeHtml('data-test="mark-update-sent"')
        ->assertSeeHtml('data-test="update-editor"')
        // Chamado direto, sem flag, o método não faz nada.
        ->call('refineWithAi')
        ->assertSet('showAiRefinement', false);

    Http::assertNothingSent();
});

test('sem chave configurada o botão de refinar também não aparece', function () {
    config(['soloboard.ai_enabled' => true, 'soloboard.ai_api_key' => null]);

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->assertDontSeeHtml('data-test="refine-update-with-ai"');
});

test('com a flag ligada o botão aparece sobre um rascunho aberto', function () {
    updatesPageAiEnabled();

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->assertSee('Refinar com AI')
        ->assertSeeHtml('data-test="refine-update-with-ai"');
});

test('refinar abre o preview e não toca no editor até o Aplicar', function () {
    updatesPageAiEnabled();

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Texto refinado pela AI.']],
        ]),
    ]);

    $component = Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        ->call('refineWithAi')
        ->assertSet('showAiRefinement', true)
        ->assertSet('aiRefinement', 'Texto refinado pela AI.')
        // O editor continua com o que o humano escreveu.
        ->assertSet('content', 'Texto escrito na mão.')
        ->assertSee('Texto refinado pela AI.');

    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');

    $component->call('applyAiRefinement')
        ->assertSet('content', 'Texto refinado pela AI.')
        ->assertSet('showAiRefinement', false)
        ->assertSet('aiRefinement', '');

    // Aplicar substitui o conteúdo do editor e o autosave segue: já está gravado.
    expect($client->fresh()->draftUpdate()->content)->toBe('Texto refinado pela AI.');

    Http::assertSentCount(1);
});

test('descartar o refinamento não altera nada', function () {
    updatesPageAiEnabled();

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Texto refinado pela AI.']],
        ]),
    ]);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        ->call('refineWithAi')
        ->assertSet('showAiRefinement', true)
        ->call('discardAiRefinement')
        ->assertSet('showAiRefinement', false)
        ->assertSet('aiRefinement', '')
        ->assertSet('content', 'Texto escrito na mão.');

    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');
});

test('erro da API degrada com toast amigável e o rascunho fica intacto', function () {
    updatesPageAiEnabled();

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'server_error'], 500),
    ]);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        ->call('refineWithAi')
        ->assertSuccessful()
        ->assertSet('showAiRefinement', false)
        ->assertSet('aiRefinement', '')
        ->assertSet('content', 'Texto escrito na mão.')
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return ($params['slots']['heading'] ?? null) === 'Não foi possível refinar';
        });

    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');
});

test('resposta truncada da AI não vira preview e o rascunho fica intacto', function () {
    updatesPageAiEnabled();

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    // Cortada no limite de tokens: aplicar isso apagaria o fim do rascunho.
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'text', 'text' => 'Texto refinado pela AI pela met']],
        ]),
    ]);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        ->call('refineWithAi')
        ->assertSet('showAiRefinement', false)
        ->assertSet('aiRefinement', '')
        ->assertSet('content', 'Texto escrito na mão.')
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return ($params['slots']['heading'] ?? null) === 'Não foi possível refinar';
        });

    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');
});

test('aplicar não escreve por cima de um rascunho editado depois do refinamento', function () {
    updatesPageAiEnabled();

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Texto refinado pela AI.']],
        ]),
    ]);

    $component = Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        ->call('refineWithAi')
        ->assertSet('showAiRefinement', true);

    // Entre pedir e aplicar, o rascunho mudou por fora — outra aba, uma tool
    // de MCP. O refinamento em tela virou texto de um rascunho que não existe
    // mais desse jeito.
    $client->fresh()->draftUpdate()->update(['content' => 'Escrito na outra aba.']);

    $component->call('applyAiRefinement')
        ->assertSet('showAiRefinement', false)
        ->assertSet('aiRefinement', '')
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return ($params['slots']['heading'] ?? null) === 'Refinamento descartado';
        });

    expect($client->fresh()->draftUpdate()->content)->toBe('Escrito na outra aba.');
});

test('aplicar não escreve num rascunho que foi enviado e recriado', function () {
    updatesPageAiEnabled();

    [$client] = updatesPageClient(['slug' => 'acme']);
    $draft = app(ClientUpdateService::class)->generate($client);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Texto refinado pela AI.']],
        ]),
    ]);

    $component = Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->set('content', 'Texto escrito na mão.')
        ->call('refineWithAi')
        ->assertSet('showAiRefinement', true);

    // O rascunho foi enviado e outro nasceu com o mesmo texto: só o id os
    // separa, e é o id que impede o refinamento de vazar para a semana nova.
    app(ClientUpdateService::class)->markSent($draft->fresh());
    ClientUpdate::factory()->for($client)->create(['content' => 'Texto escrito na mão.']);

    $component->call('applyAiRefinement')
        ->assertSet('showAiRefinement', false)
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return ($params['slots']['heading'] ?? null) === 'Refinamento descartado';
        });

    expect($client->fresh()->draftUpdate()->content)->toBe('Texto escrito na mão.');
});

test('a janela do rate limit é tomada antes da chamada, e vale mesmo se a API falhar', function () {
    updatesPageAiEnabled();

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    $cacheKey = 'ai_refine_update_'.auth()->id();

    // Checar e depois gravar deixaria dois cliques simultâneos passarem
    // juntos: quando a requisição sai, a janela já tem que estar tomada.
    Http::fake(function () use ($cacheKey) {
        expect(Cache::has($cacheKey))->toBeTrue();

        return Http::response(['error' => 'server_error'], 500);
    });

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->call('refineWithAi')
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return ($params['slots']['heading'] ?? null) === 'Não foi possível refinar';
        });

    // O pedido saiu, e é o pedido que se limita: a falha consome o minuto.
    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->call('refineWithAi')
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return ($params['slots']['heading'] ?? null) === 'Aguarde';
        });

    Http::assertSentCount(1);
});

test('refinar respeita o limite de uma chamada por minuto', function () {
    updatesPageAiEnabled();

    [$client] = updatesPageClient(['slug' => 'acme']);
    app(ClientUpdateService::class)->generate($client);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Texto refinado pela AI.']],
        ]),
    ]);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->call('refineWithAi')
        ->assertSet('showAiRefinement', true);

    Http::assertSentCount(1);

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->call('refineWithAi')
        ->assertSet('showAiRefinement', false)
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return ($params['slots']['heading'] ?? null) === 'Aguarde';
        });

    Http::assertSentCount(1);

    // Passado o minuto, a porta reabre.
    $this->travel(61)->seconds();

    Livewire::withQueryParams(['client' => 'acme'])
        ->test('pages::updates')
        ->call('refineWithAi')
        ->assertSet('showAiRefinement', true);

    Http::assertSentCount(2);
});

test('a página de clientes leva para o update do cliente', function () {
    Client::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

    Livewire::test('pages::clients')
        ->assertSeeHtml('data-test="client-generate-update-acme"')
        ->assertSeeHtml(route('updates', ['client' => 'acme'], absolute: false));
});
