<?php

use App\Enums\PolicyKey;
use App\Models\Client;
use App\Models\PolicyVersion;
use App\Models\User;

/**
 * O painel de políticas explícitas num browser de verdade (issue #154).
 *
 * A suíte Feature já prova o contrato do componente pelo lado do servidor.
 * O que ela não alcança é o gesto: que o botão do header do Kanban abre um
 * modal com as três partes visíveis, que as mecânicas escritas na tela são
 * as da config vigente, que salvar uma seção *acrescenta* uma versão sem
 * apagar a anterior — e que o histórico mostra as duas, com data e nota —, e
 * que a cutucada dos clientes sem acordo aparece com link para /clients.
 *
 * Como nos vizinhos ({@see FlowPageFlowTest}, {@see IntangibleHungerFlowTest}),
 * o relógio é deixado em paz: as datas conferidas saem do `now()` real.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    // O banco de teste nasce sem o seeder das políticas, e a tabela é
    // append-only — não há como limpá-la nem faz falta: cada cenário
    // declara as versões que quer medir.
    Client::query()->delete();
});

/**
 * Abre o painel a partir do botão do header do Kanban — o único caminho
 * até ele, já que a feature não tem rota nem item de sidebar.
 */
function openPolicies()
{
    return visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-test="board-policies-button"]')
        ->click('[data-test="board-policies-button"]')
        ->waitForText('Políticas do quadro');
}

// ── 1. O botão abre o painel com as três partes ──────────────────────────

test('the Políticas button in the Kanban header opens the panel with its three parts', function (): void {
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'Teste passando e revisado.', 'Padrão inicial do método');

    Client::factory()->create([
        'name' => 'Cliente com acordo',
        'is_active' => true,
        'response_agreement' => 'Respondo em até um dia útil.',
    ]);

    openPolicies()
        // Mecânicas
        ->assertSee('Como o quadro funciona')
        ->assertPresent('[data-test="policy-mechanic-wip_limit_doing"]')
        ->assertPresent('[data-test="policy-mechanic-single_emergency"]')
        ->assertPresent('[data-test="policy-mechanic-pull_order"]')
        ->assertPresent('[data-test="policy-mechanic-risk_window"]')
        // Seções humanas
        ->assertSee('O que eu escrevi')
        ->assertPresent('[data-test="policy-section-definition_of_done"]')
        ->assertPresent('[data-test="policy-section-definition_of_ready"]')
        ->assertPresent('[data-test="policy-section-working_agreements"]')
        ->assertSee('Teste passando e revisado.')
        // Uma seção nunca escrita diz isso, em vez de fingir vazio
        ->assertPresent('[data-test="policy-empty-definition_of_ready"]')
        // Acordos de resposta
        ->assertPresent('[data-test="policy-response-agreements"]')
        ->assertSee('Acordos de resposta')
        ->assertSee('Cliente com acordo')
        ->assertSee('Respondo em até um dia útil.')
        ->assertPresent('[data-test="policy-clients-link"]')
        ->assertNoJavaScriptErrors();
});

// ── 2. As mecânicas saem da config vigente ───────────────────────────────

test('the mechanics render the config in force, not a number typed into the panel', function (): void {
    config(['soloboard.wip_limit_doing' => 2]);

    openPolicies()
        ->assertSee('No máximo 2 itens em Fazendo ao mesmo tempo.')
        ->assertSee('0/2 agora');
});

test('changing the config changes what the panel says', function (): void {
    config(['soloboard.wip_limit_doing' => 5]);

    openPolicies()
        ->assertSee('No máximo 5 itens em Fazendo ao mesmo tempo.')
        ->assertSee('0/5 agora')
        ->assertDontSee('No máximo 2 itens em Fazendo ao mesmo tempo.');
});

// ── 3. Editar acrescenta versão; a anterior continua legível ─────────────

test('saving a section appends a version and leaves the previous one in the history', function (): void {
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'Versão original do Feito.', 'Padrão inicial do método');

    $page = openPolicies()
        ->assertSee('Versão original do Feito.')
        ->assertSee('Histórico (1)')
        ->click('[data-test="policy-edit-definition_of_done"]')
        ->waitForText('Salvar versão')
        // A nota não é pré-preenchida: ela explica *esta* mudança.
        ->assertValue('[data-test="policy-note-definition_of_done"]', '')
        ->fill('[data-test="policy-body-definition_of_done"]', 'Versão nova do Feito.')
        ->fill('[data-test="policy-note-definition_of_done"]', 'Passei a exigir teste antes de Feito')
        ->click('[data-test="policy-save-definition_of_done"]')
        ->waitForText('Versão nova do Feito.');

    // Duas linhas, não uma sobrescrita.
    $versions = PolicyVersion::history(PolicyKey::DefinitionOfDone);

    expect($versions)->toHaveCount(2)
        ->and($versions->first()->body)->toBe('Versão nova do Feito.')
        ->and($versions->first()->note)->toBe('Passei a exigir teste antes de Feito')
        ->and($versions->last()->body)->toBe('Versão original do Feito.');

    // E o histórico na tela mostra as duas, com data e nota, a vigente no topo.
    $page->assertSee('Histórico (2)')
        ->click('[data-test="policy-history-toggle-definition_of_done"]')
        // A nota da v1 só existe no histórico — o rodapé da vigente mostra a nova.
        ->waitForText('Padrão inicial do método')
        ->assertPresent('[data-test="policy-history-definition_of_done"]')
        ->assertSee('Passei a exigir teste antes de Feito')
        ->assertSee('Vigente')
        ->assertSee(now()->format('d/m/Y'))
        ->assertNoJavaScriptErrors();
});

test('a section never written can be written from the panel, with the note left blank', function (): void {
    openPolicies()
        ->assertPresent('[data-test="policy-empty-working_agreements"]')
        ->click('[data-test="policy-edit-working_agreements"]')
        ->waitForText('Salvar versão')
        ->fill('[data-test="policy-body-working_agreements"]', 'Puxo uma coisa de cada vez.')
        ->click('[data-test="policy-save-working_agreements"]')
        ->waitForText('Puxo uma coisa de cada vez.')
        ->assertMissing('[data-test="policy-empty-working_agreements"]')
        ->assertNoJavaScriptErrors();

    $version = PolicyVersion::current(PolicyKey::WorkingAgreements);

    expect($version->body)->toBe('Puxo uma coisa de cada vez.')
        // Nota em branco vira null, não string vazia.
        ->and($version->note)->toBeNull();
});

// ── 4. A cutucada dos clientes sem acordo ────────────────────────────────

test('the nudge counts the active clients without an agreement and links to Clientes', function (): void {
    Client::factory()->create([
        'name' => 'Sem acordo um',
        'is_active' => true,
        'response_agreement' => null,
    ]);

    Client::factory()->create([
        'name' => 'Sem acordo dois',
        'is_active' => true,
        // Só espaços conta como ausente.
        'response_agreement' => '   ',
    ]);

    Client::factory()->create([
        'name' => 'Inativo sem acordo',
        'is_active' => false,
        'response_agreement' => null,
    ]);

    $page = openPolicies()
        ->assertPresent('[data-test="policy-agreement-nudge"]')
        ->assertSee('2 clientes ativos sem acordo — definir');

    $page->click('[data-test="policy-agreement-nudge"]')
        ->waitForText('Clientes')
        ->assertUrlIs(url('/clients'))
        ->assertNoJavaScriptErrors();
});

test('with every active client covered the nudge stays away', function (): void {
    Client::factory()->create([
        'name' => 'Coberto',
        'is_active' => true,
        'response_agreement' => 'Respondo em até um dia útil.',
    ]);

    openPolicies()
        ->assertSee('Coberto')
        ->assertMissing('[data-test="policy-agreement-nudge"]');
});
