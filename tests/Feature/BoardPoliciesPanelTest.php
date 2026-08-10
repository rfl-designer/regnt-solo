<?php

use App\Enums\PolicyKey;
use App\Models\Client;
use App\Models\PolicyVersion;
use App\Models\User;
use Livewire\Livewire;

/**
 * O painel de políticas no header do Kanban (issue #154): as três partes,
 * o editor que só insere e o histórico em leitura.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('o Kanban traz o botão que abre o painel', function () {
    Livewire::test('pages::⚡kanban')
        ->assertSeeHtml('data-test="board-policies-button"');
});

test('o painel mostra as três partes', function () {
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'Entregue e testado');
    Client::factory()->create([
        'name' => 'Acme',
        'is_active' => true,
        'response_agreement' => 'Respondo em até 1 dia útil',
    ]);

    Livewire::test('board-policies')
        ->call('open')
        ->assertSet('showModal', true)
        // 1. mecânicas
        ->assertSee('Limite de Fazendo')
        ->assertSee('Emergência única')
        ->assertSee('Ordem de puxar')
        ->assertSee('Janela de risco')
        // 2. seções escritas
        ->assertSee('Definição de Feito')
        ->assertSee('Definição de Pronto')
        ->assertSee('Acordos de trabalho')
        ->assertSee('Entregue e testado')
        // 3. acordos de resposta
        ->assertSee('Acordos de resposta')
        ->assertSee('Acme')
        ->assertSee('Respondo em até 1 dia útil');
});

test('mudar a config muda o que o painel mostra', function () {
    config()->set('soloboard.wip_limit_doing', 7);

    Livewire::test('board-policies')
        ->call('open')
        ->assertSee('No máximo 7 itens em Fazendo');
});

test('salvar uma seção insere versão nova com a nota e não sobrescreve', function () {
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'Primeira versão');

    Livewire::test('board-policies')
        ->call('open')
        ->call('edit', PolicyKey::DefinitionOfDone->value)
        ->assertSet('body', 'Primeira versão')
        // A nota explica *esta* mudança, então nunca vem pré-preenchida.
        ->assertSet('note', '')
        ->set('body', 'Segunda versão')
        ->set('note', 'Agora exijo teste')
        ->call('save')
        ->assertSet('editingKey', null)
        ->assertHasNoErrors();

    expect(PolicyVersion::query()->forKey(PolicyKey::DefinitionOfDone)->count())->toBe(2)
        ->and(PolicyVersion::current(PolicyKey::DefinitionOfDone)->body)->toBe('Segunda versão')
        ->and(PolicyVersion::current(PolicyKey::DefinitionOfDone)->note)->toBe('Agora exijo teste');
});

test('a nota é opcional ao salvar', function () {
    Livewire::test('board-policies')
        ->call('open')
        ->call('edit', PolicyKey::WorkingAgreements->value)
        ->set('body', 'Puxar, nunca empurrar')
        ->call('save')
        ->assertHasNoErrors();

    expect(PolicyVersion::current(PolicyKey::WorkingAgreements)->note)->toBeNull();
});

test('uma política sem texto é recusada', function () {
    Livewire::test('board-policies')
        ->call('open')
        ->call('edit', PolicyKey::DefinitionOfReady->value)
        ->set('body', '')
        ->call('save')
        ->assertHasErrors(['body' => 'required']);

    expect(PolicyVersion::current(PolicyKey::DefinitionOfReady))->toBeNull();
});

test('o histórico lista as versões com data e nota, a vigente primeiro', function () {
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'v1', 'Padrão inicial do método');
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'v2', 'Passei a exigir teste');

    $component = Livewire::test('board-policies')
        ->call('open')
        ->call('toggleHistory', PolicyKey::DefinitionOfDone->value)
        ->assertSet('historyKey', PolicyKey::DefinitionOfDone->value)
        ->assertSee('Padrão inicial do método')
        ->assertSee('Passei a exigir teste')
        ->assertSee('Vigente')
        ->assertSee(now()->format('d/m/Y'));

    // A vigente encabeça a lista.
    expect($component->instance()->history->pluck('note')->all())
        ->toBe(['Passei a exigir teste', 'Padrão inicial do método']);

    // Fechar é o mesmo gesto.
    $component
        ->call('toggleHistory', PolicyKey::DefinitionOfDone->value)
        ->assertSet('historyKey', null);
});

test('os acordos de resposta são somente leitura, com link para Clientes', function () {
    Client::factory()->create(['name' => 'Acme', 'is_active' => true, 'response_agreement' => 'Em 1 dia útil']);

    Livewire::test('board-policies')
        ->call('open')
        ->assertSeeHtml('data-test="policy-clients-link"')
        ->assertSeeHtml('href="'.route('clients').'"');
});

test('clientes ativos sem acordo aparecem na cutucada', function () {
    Client::factory()->create(['is_active' => true, 'response_agreement' => 'Em 1 dia útil']);
    Client::factory()->count(2)->create(['is_active' => true, 'response_agreement' => null]);
    Client::factory()->create(['is_active' => false, 'response_agreement' => null]);

    Livewire::test('board-policies')
        ->call('open')
        ->assertSeeHtml('data-test="policy-agreement-nudge"')
        ->assertSee('2 clientes ativos sem acordo');
});

test('sem cliente pendente a cutucada some', function () {
    Client::factory()->create(['is_active' => true, 'response_agreement' => 'Em 1 dia útil']);

    Livewire::test('board-policies')
        ->call('open')
        ->assertDontSeeHtml('data-test="policy-agreement-nudge"');
});

/**
 * Regressões do review do #154.
 */
test('o corpo da política é renderizado sem HTML cru nem link inseguro', function () {
    PolicyVersion::record(
        PolicyKey::DefinitionOfDone,
        "<script>alert(1)</script>\n\n<img src=x onerror=\"alert(document.domain)\">",
    );

    // Link inseguro num corpo puramente markdown: outro caminho do helper.
    PolicyVersion::record(PolicyKey::DefinitionOfReady, '[clique](javascript:alert(1))');

    $html = Livewire::test('board-policies')->call('open')->html();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('onerror')
        ->not->toContain('href="javascript:');
});

test('chave inválida numa ação é recusada em silêncio, não em 500', function () {
    $component = Livewire::test('board-policies')
        ->call('open')
        ->call('edit', 'invalid')
        ->assertOk();

    expect($component->instance()->editingKey)->toBeNull();

    $component->call('toggleHistory', 'invalid')->assertOk();

    expect($component->instance()->historyKey)->toBeNull();

    // save() sem editor aberto não converte chave nenhuma.
    $component->call('save')->assertOk();

    expect(PolicyVersion::query()->count())->toBe(0);
});
