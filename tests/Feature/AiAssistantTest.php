<?php

use App\Models\Activity;
use App\Services\AiAssistantService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('analyzeBacklog returns analysis with mocked API', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $tasks = Activity::factory()->count(2)->create();

    $analysis = [
        [
            'task_id' => $tasks[0]->id,
            'action' => 'prioritize',
            'reason' => 'This task aligns with current project goals',
            'suggested_service_class' => 'emergency',
            'suggested_project' => null,
        ],
        [
            'task_id' => $tasks[1]->id,
            'action' => 'archive',
            'reason' => 'Task has been inactive for too long',
            'suggested_service_class' => null,
            'suggested_project' => null,
        ],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($analysis)],
            ],
        ]),
    ]);

    $service = app(AiAssistantService::class);
    $result = $service->analyzeBacklog($tasks->fresh());

    expect($result)->toHaveCount(2)
        ->and($result[0]['action'])->toBe('prioritize')
        ->and($result[0]['suggested_service_class'])->toBe('emergency')
        ->and($result[1]['action'])->toBe('archive');

    Http::assertSentCount(1);
});

test('detectPatterns returns patterns with mocked API', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $patterns = [
        [
            'type' => 'over_commitment',
            'message' => 'You have 12 tasks in progress simultaneously',
            'severity' => 'warning',
            'action_url' => null,
            'action_label' => null,
        ],
        [
            'type' => 'positive_trend',
            'message' => 'Your completion rate increased by 20% this week',
            'severity' => 'info',
            'action_url' => null,
            'action_label' => null,
        ],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($patterns)],
            ],
        ]),
    ]);

    $service = app(AiAssistantService::class);
    $result = $service->detectPatterns([
        'tasks_completed' => 15,
        'tasks_created' => 8,
        'total_focus_minutes' => 480,
    ]);

    expect($result)->toHaveCount(2)
        ->and($result[0]['type'])->toBe('over_commitment')
        ->and($result[0]['severity'])->toBe('warning')
        ->and($result[1]['type'])->toBe('positive_trend');

    Http::assertSentCount(1);
});

test('service handles connection exception gracefully', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::failedConnection(),
    ]);

    $service = app(AiAssistantService::class);
    $tasks = Activity::factory()->count(2)->create();

    $result = $service->analyzeBacklog($tasks->fresh());

    expect($result)->toBe([]);
});

test('refineUpdate manda o contrato de copy editor e devolve só o texto', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $draft = "**Entregue**\n- Checkout novo, no ar desde 05/08\n\n**Próximo**\n- Relatório de vendas";

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => "  **Entregue**\n- O checkout novo está no ar desde 05/08\n\n**Próximo**\n- Relatório de vendas  "],
            ],
        ]),
    ]);

    $result = app(AiAssistantService::class)->refineUpdate($draft);

    // A saída é prosa, não JSON — e volta aparada, pronta para o editor.
    expect($result)->toBe("**Entregue**\n- O checkout novo está no ar desde 05/08\n\n**Próximo**\n- Relatório de vendas");

    Http::assertSent(function (Request $request) use ($draft): bool {
        $system = $request['system'];

        // O contrato é o que separa um copy editor de um redator: fatos
        // intocáveis, nada de resumir nem reordenar, e só o texto na resposta.
        expect($system)
            ->toContain('copy editor')
            ->toContain('Never add, remove or alter any item, state, date, number, name or commitment')
            ->toContain('Never summarise, merge or drop content, and never reorder the blocks')
            ->toContain('Brazilian Portuguese, professional and close')
            ->toContain('degrade well in any channel')
            ->toContain('Answer with the refined update text only');

        // O rascunho vai inteiro, com as edições manuais que já estiverem nele.
        return str_contains($request['messages'][0]['content'], $draft);
    });

    Http::assertSentCount(1);
});

test('refineUpdate degrada para vazio quando a API falha', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'server_error'], 500),
    ]);

    expect(app(AiAssistantService::class)->refineUpdate('Rascunho qualquer'))->toBe('');
});

test('refineUpdate degrada para vazio quando a resposta vem sem texto', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => []]),
    ]);

    expect(app(AiAssistantService::class)->refineUpdate('Rascunho qualquer'))->toBe('');
});

test('refineUpdate não chama a API sem flag nem com rascunho vazio', function () {
    config(['soloboard.ai_enabled' => false, 'soloboard.ai_api_key' => 'test-key']);

    expect(app(AiAssistantService::class)->refineUpdate('Rascunho qualquer'))->toBe('');

    config(['soloboard.ai_enabled' => true]);

    expect(app(AiAssistantService::class)->refineUpdate("  \n "))->toBe('');

    Http::assertNothingSent();
});

test('service returns empty for empty weekly data', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $service = app(AiAssistantService::class);

    expect($service->detectPatterns([]))->toBe([]);

    Http::assertNothingSent();
});
