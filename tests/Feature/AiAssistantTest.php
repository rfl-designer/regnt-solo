<?php

use App\Models\Activity;
use App\Services\AiAssistantService;
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

test('service returns empty for empty weekly data', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $service = app(AiAssistantService::class);

    expect($service->detectPatterns([]))->toBe([]);

    Http::assertNothingSent();
});
