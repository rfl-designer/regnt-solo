<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientUpdate;
use App\Models\MorningRitual;
use App\Models\Project;
use App\Models\User;

/**
 * O update semanal num browser de verdade (issue #149).
 *
 * A suíte Feature já prova o contrato do componente pelo lado do servidor.
 * O que ela não alcança é justamente o que a pessoa toca: que o texto
 * digitado chega ao banco no blur, que "Copiar" leva markdown para o
 * clipboard sem gravar nada, que regerar por cima de uma edição pede
 * confirmação antes de descartá-la, e que marcar como enviado tira o
 * rascunho da tela e o coloca no histórico.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * Escuta o payload que "Copiar" dispara, para conferir a cópia sem pedir
 * permissão de clipboard a um browser headless — o markdown entregue ao
 * `navigator.clipboard` *é* o comportamento sob teste.
 */
function captureCopiedUpdateScript(): string
{
    return <<<'JS'
    (() => {
        window.__copiedUpdate = null;
        window.addEventListener('copy-to-clipboard', e => { window.__copiedUpdate = e.detail.markdown });

        return 'listening';
    })()
    JS;
}

/**
 * O texto que está no editor agora — o valor do textarea, que é o que a
 * pessoa lê e edita.
 */
function updateEditorValueScript(): string
{
    return "document.querySelector('[data-test=\"update-editor\"]').value";
}

/**
 * Um cliente devido hoje, com um projeto e uma spec esperando aprovação —
 * o mínimo para que o gerador tenha o que dizer.
 *
 * @return array{0: Client, 1: Activity}
 */
function clientWithSomethingToSay(): array
{
    $client = Client::factory()->create([
        'name' => 'Acme Corp',
        'slug' => 'acme',
        'update_day' => MorningRitual::businessNow()->dayOfWeekIso,
        // 00:01 põe o compromisso da cadência sempre no passado de hoje,
        // qualquer que seja a hora em que a suíte rode: antes de enviar o
        // cliente vence hoje, depois de enviar fica em dia. Uma hora-alvo
        // no meio do dia faria o teste depender do relógio da máquina.
        'update_time' => '00:01',
    ]);

    $project = Project::factory()->create(['client_id' => $client->id]);

    $spec = Activity::factory()->epic()->awaitingApproval()->create([
        'project_id' => $project->id,
        'title' => 'Spec do checkout',
    ]);

    withSpecHistory($spec, [[ActivityStatus::AwaitingApproval, now()->subDays(2)->toDateTimeString()]]);

    return [$client, $spec];
}

test('a fila mostra quem está devido e gerar monta o rascunho do quadro', function (): void {
    [$client] = clientWithSomethingToSay();

    $page = visit('/updates')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-test="update-queue"]')
        ->assertSee('Acme Corp')
        ->assertSee('Vence hoje');

    $page->click('[data-test="queue-item-acme"]')
        ->waitForText('Nenhum rascunho aberto')
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho')
        ->assertNoJavaScriptErrors();

    // O texto vive no valor do textarea, não no corpo da página: é ele que
    // a pessoa edita, e é ele que precisa ter vindo do quadro.
    expect($page->script(updateEditorValueScript()))->toContain('Spec do checkout');

    expect($client->fresh()->draftUpdate())->not->toBeNull();
});

test('o que se digita no editor chega ao banco no blur', function (): void {
    [$client] = clientWithSomethingToSay();

    $page = visit(route('updates', ['client' => 'acme']))
        ->assertNoJavaScriptErrors()
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho');

    // Sair do campo é o que dispara o autosave.
    $page->fill('[data-test="update-editor"]', 'Oi! Escrevi eu mesmo.')
        ->keys('[data-test="update-editor"]', 'Tab')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($client->fresh()->draftUpdate()->content)->toBe('Oi! Escrevi eu mesmo.');
});

test('copiar leva o texto ao clipboard e não marca nada como enviado', function (): void {
    [$client] = clientWithSomethingToSay();

    $page = visit(route('updates', ['client' => 'acme']))
        ->assertNoJavaScriptErrors()
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho');

    $page->script(captureCopiedUpdateScript());

    $page->click('[data-test="copy-update"]')
        ->waitForText('Copiado!')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.__copiedUpdate'))->toContain('Spec do checkout')
        ->and($client->fresh()->draftUpdate()->sent_at)->toBeNull();
});

test('regerar por cima de uma edição pede confirmação antes de descartá-la', function (): void {
    [$client] = clientWithSomethingToSay();

    $page = visit(route('updates', ['client' => 'acme']))
        ->assertNoJavaScriptErrors()
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho');

    $page->fill('[data-test="update-editor"]', 'Texto que eu escrevi na mão.')
        ->keys('[data-test="update-editor"]', 'Tab')
        ->wait(1)
        ->click('[data-test="regenerate-update"]')
        ->waitForText('Regerar o rascunho?')
        ->assertSee('descarta a sua edição')
        ->assertNoJavaScriptErrors();

    // Enquanto a pergunta está na tela, o texto da pessoa continua inteiro.
    expect($client->fresh()->draftUpdate()->content)->toBe('Texto que eu escrevi na mão.');

    $page->click('[data-test="confirm-regenerate"]')
        ->waitForText('Rascunho regerado')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($client->fresh()->draftUpdate()->content)->toContain('Spec do checkout');
});

test('marcar como enviado limpa o rascunho e escreve o histórico', function (): void {
    [$client] = clientWithSomethingToSay();

    $page = visit(route('updates', ['client' => 'acme']))
        ->assertNoJavaScriptErrors()
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho');

    $page->click('[data-test="mark-update-sent"]')
        ->waitForText('O relógio da cadência zerou')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $client = $client->fresh();

    expect($client->draftUpdate())->toBeNull()
        ->and($client->lastSentUpdate())->not->toBeNull();

    // E a fila deixa de cobrar: o relógio zerou de verdade.
    visit(route('updates', ['client' => 'acme']))
        ->assertSee('Em dia')
        ->assertSee('Histórico')
        ->assertNoJavaScriptErrors();
});

test('o histórico mostra o que o cliente recebeu', function (): void {
    $client = Client::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme']);

    ClientUpdate::factory()->for($client)->sent(now()->subWeek()->toDateTimeString())->create([
        'content' => "**Entregue**\n- Portal de faturas — validada",
    ]);

    visit(route('updates', ['client' => 'acme']))
        ->assertPresent('[data-test="update-history"]')
        ->assertSee('Portal de faturas')
        ->assertNoJavaScriptErrors();
});
