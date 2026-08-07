<?php

use App\Models\Activity;
use App\Services\AiAssistantService;
use Illuminate\Support\Facades\Http;

test('AI service returns empty when disabled', function () {
    config(['soloboard.ai_enabled' => false]);

    $service = app(AiAssistantService::class);
    $tasks = Activity::factory()->count(3)->create();

    expect($service->suggestDailyPlan($tasks->fresh()))->toBe([])
        ->and($service->analyzeBacklog($tasks->fresh()))->toBe([])
        ->and($service->detectPatterns(['some' => 'data']))->toBe([]);

    Http::assertNothingSent();
});

test('AI service returns empty when api key is missing', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => null,
    ]);

    $service = app(AiAssistantService::class);
    $tasks = Activity::factory()->count(2)->create();

    expect($service->suggestDailyPlan($tasks->fresh()))->toBe([]);

    Http::assertNothingSent();
});

test('suggestDailyPlan returns suggestions with mocked API', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $tasks = Activity::factory()->count(2)->create();

    $suggestions = [
        ['task_id' => $tasks[0]->id, 'reason' => 'High priority and due soon', 'priority_score' => 90],
        ['task_id' => $tasks[1]->id, 'reason' => 'Good momentum task', 'priority_score' => 60],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($suggestions)],
            ],
        ]),
    ]);

    $service = app(AiAssistantService::class);
    $result = $service->suggestDailyPlan($tasks->fresh());

    expect($result)->toHaveCount(2)
        ->and($result[0]['task_id'])->toBe($tasks[0]->id)
        ->and($result[0]['reason'])->toBe('High priority and due soon')
        ->and($result[0]['priority_score'])->toBe(90)
        ->and($result[1]['priority_score'])->toBe(60);

    Http::assertSentCount(1);
});

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

test('service handles API errors gracefully', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'rate_limited'], 429),
    ]);

    $service = app(AiAssistantService::class);
    $tasks = Activity::factory()->count(2)->create();

    $result = $service->suggestDailyPlan($tasks->fresh());

    expect($result)->toBe([]);
});

test('service handles invalid JSON response gracefully', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => 'This is not valid JSON at all {{{'],
            ],
        ]),
    ]);

    $service = app(AiAssistantService::class);
    $tasks = Activity::factory()->count(2)->create();

    $result = $service->suggestDailyPlan($tasks->fresh());

    expect($result)->toBe([]);
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

test('service sends correct headers to Anthropic API', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-api-key-123',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => '[]'],
            ],
        ]),
    ]);

    $service = app(AiAssistantService::class);
    $tasks = Activity::factory()->count(1)->create();

    $service->suggestDailyPlan($tasks->fresh());

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-api-key', 'test-api-key-123')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request['model'] === 'claude-sonnet-4-20250514';
    });
});

test('service returns empty for empty task collection', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $service = app(AiAssistantService::class);

    expect($service->suggestDailyPlan(collect()))->toBe([])
        ->and($service->analyzeBacklog(collect()))->toBe([]);

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

test('service parses JSON embedded in markdown response', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $tasks = Activity::factory()->count(1)->create();
    $suggestions = [['task_id' => $tasks[0]->id, 'reason' => 'Important', 'priority_score' => 85]];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => "Here are my suggestions:\n```json\n".json_encode($suggestions)."\n```"],
            ],
        ]),
    ]);

    $service = app(AiAssistantService::class);
    $result = $service->suggestDailyPlan($tasks->fresh());

    expect($result)->toHaveCount(1)
        ->and($result[0]['task_id'])->toBe($tasks[0]->id)
        ->and($result[0]['priority_score'])->toBe(85);
});
