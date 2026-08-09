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

test('a página de clientes leva para o update do cliente', function () {
    Client::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

    Livewire::test('pages::clients')
        ->assertSeeHtml('data-test="client-generate-update-acme"')
        ->assertSeeHtml(route('updates', ['client' => 'acme'], absolute: false));
});
