<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\MorningRitual;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Refinar o update com AI num browser de verdade (issue #151).
 *
 * A suíte Feature já prova o contrato do componente pelo lado do servidor.
 * O que ela não alcança é o que a pessoa toca: que o botão só existe com a
 * flag ligada, que o preview abre num modal de verdade com o texto que a API
 * devolveu, que Aplicar troca o valor do textarea que ela está lendo, e que
 * Descartar fecha o modal deixando o editor exatamente como estava.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * O texto que está no editor agora — o valor do textarea, que é o que a
 * pessoa lê e edita.
 */
function aiRefineEditorValueScript(): string
{
    return "document.querySelector('[data-test=\"update-editor\"]').value";
}

function aiRefineEnabled(): void
{
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);
}

/**
 * A resposta que a API do Anthropic devolveria, com o contrato que o serviço
 * exige para confiar no texto: um `end_turn` limpo.
 */
function fakeRefinedUpdate(string $text): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
        ]),
    ]);
}

/**
 * Um cliente devido hoje, com um projeto e uma spec esperando aprovação — o
 * mínimo para que o gerador determinístico tenha o que dizer.
 */
function clientWithADraftToRefine(): Client
{
    $client = Client::factory()->create([
        'name' => 'Acme Corp',
        'slug' => 'acme',
        'update_day' => MorningRitual::businessNow()->dayOfWeekIso,
        'update_time' => '14:00',
    ]);

    $project = Project::factory()->create(['client_id' => $client->id]);

    $spec = Activity::factory()->epic()->awaitingApproval()->create([
        'project_id' => $project->id,
        'title' => 'Spec do checkout',
    ]);

    withSpecHistory($spec, [[ActivityStatus::AwaitingApproval, now()->subDays(2)->toDateTimeString()]]);

    return $client;
}

test('sem a flag ligada a barra do rascunho não oferece refinar com AI', function (): void {
    config(['soloboard.ai_enabled' => false, 'soloboard.ai_api_key' => 'test-key']);

    clientWithADraftToRefine();

    visit(route('updates', ['client' => 'acme']))
        ->assertNoJavaScriptErrors()
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho')
        ->assertMissing('[data-test="refine-update-with-ai"]')
        ->assertDontSee('Refinar com AI')
        // O fluxo determinístico segue inteiro sem a AI.
        ->assertPresent('[data-test="regenerate-update"]')
        ->assertPresent('[data-test="copy-update"]')
        ->assertPresent('[data-test="mark-update-sent"]')
        ->assertNoJavaScriptErrors();

    Http::assertNothingSent();
});

test('com a flag ligada refinar abre o preview com o texto da AI, sem tocar no editor', function (): void {
    aiRefineEnabled();
    fakeRefinedUpdate('Oi! Esta semana a spec do checkout ficou pronta para a sua aprovação.');

    $client = clientWithADraftToRefine();

    $page = visit(route('updates', ['client' => 'acme']))
        ->assertNoJavaScriptErrors()
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho')
        ->assertSee('Refinar com AI');

    $original = $page->script(aiRefineEditorValueScript());

    $page->click('[data-test="refine-update-with-ai"]')
        ->waitForText('Refinado com AI')
        ->assertPresent('[data-test="ai-refinement-preview"]')
        ->assertSeeIn('[data-test="ai-refinement-preview"]', 'ficou pronta para a sua aprovação')
        ->assertNoJavaScriptErrors();

    // O preview é uma proposta: nem o editor nem o banco mudaram ainda.
    expect($page->script(aiRefineEditorValueScript()))->toBe($original)
        ->and($client->fresh()->draftUpdate()->content)->toBe($original);
});

test('aplicar troca o texto do editor e grava o refinamento', function (): void {
    aiRefineEnabled();
    fakeRefinedUpdate('Texto refinado pela AI.');

    $client = clientWithADraftToRefine();

    $page = visit(route('updates', ['client' => 'acme']))
        ->assertNoJavaScriptErrors()
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho');

    $page->click('[data-test="refine-update-with-ai"]')
        ->waitForText('Refinado com AI')
        ->click('[data-test="apply-ai-refinement"]')
        ->waitForText('Refinamento aplicado')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($page->script(aiRefineEditorValueScript()))->toBe('Texto refinado pela AI.')
        ->and($client->fresh()->draftUpdate()->content)->toBe('Texto refinado pela AI.');
});

test('descartar fecha o preview e deixa o rascunho intacto', function (): void {
    aiRefineEnabled();
    fakeRefinedUpdate('Texto refinado pela AI.');

    $client = clientWithADraftToRefine();

    $page = visit(route('updates', ['client' => 'acme']))
        ->assertNoJavaScriptErrors()
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho');

    $original = $page->script(aiRefineEditorValueScript());

    $page->click('[data-test="refine-update-with-ai"]')
        ->waitForText('Refinado com AI')
        ->click('[data-test="discard-ai-refinement"]')
        ->wait(1)
        ->assertDontSee('Texto refinado pela AI.')
        ->assertNoJavaScriptErrors();

    expect($page->script(aiRefineEditorValueScript()))->toBe($original)
        ->and($client->fresh()->draftUpdate()->content)->toBe($original);
});

test('um erro da API degrada com toast e o rascunho continua como estava', function (): void {
    aiRefineEnabled();

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'server_error'], 500),
    ]);

    $client = clientWithADraftToRefine();

    $page = visit(route('updates', ['client' => 'acme']))
        ->assertNoJavaScriptErrors()
        ->click('[data-test="generate-update"]')
        ->waitForText('Salva sozinho');

    $original = $page->script(aiRefineEditorValueScript());

    $page->click('[data-test="refine-update-with-ai"]')
        ->waitForText('Não foi possível refinar')
        ->assertMissing('[data-test="ai-refinement-preview"]')
        ->assertNoJavaScriptErrors();

    expect($page->script(aiRefineEditorValueScript()))->toBe($original)
        ->and($client->fresh()->draftUpdate()->content)->toBe($original);
});
