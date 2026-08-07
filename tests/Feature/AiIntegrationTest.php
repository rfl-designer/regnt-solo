<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ── Daily Planner AI Integration ──────────────────────────────────────

test('AI suggest button is hidden when ai_enabled is false', function () {
    config(['soloboard.ai_enabled' => false]);

    Livewire::test('pages::daily-planner')
        ->assertDontSee('Sugerir plano');
});

test('AI suggest button is visible when ai_enabled is true', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    Livewire::test('pages::daily-planner')
        ->assertSee('Sugerir plano');
});

test('suggestDailyPlan calls service and shows suggestions', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $task = Activity::factory()->todo()->create(['title' => 'Task sugerida']);

    $suggestions = [
        ['task_id' => $task->id, 'reason' => 'High priority task', 'priority_score' => 85],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($suggestions)],
            ],
        ]),
    ]);

    Livewire::test('pages::daily-planner')
        ->call('suggestDailyPlan')
        ->assertSet('showAiSuggestions', true)
        ->assertSet('aiLoading', false)
        ->assertCount('aiSuggestions', 1);

    Http::assertSentCount(1);
});

test('suggestDailyPlan does nothing when AI is disabled', function () {
    config(['soloboard.ai_enabled' => false]);

    Livewire::test('pages::daily-planner')
        ->call('suggestDailyPlan')
        ->assertSet('showAiSuggestions', false)
        ->assertSet('aiSuggestions', []);

    Http::assertNothingSent();
});

test('suggestDailyPlan rate limiting prevents rapid API calls', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Activity::factory()->todo()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => '[]'],
            ],
        ]),
    ]);

    // First call should succeed
    Livewire::test('pages::daily-planner')
        ->call('suggestDailyPlan')
        ->assertSet('showAiSuggestions', true);

    Http::assertSentCount(1);

    // Second call should be rate limited
    Livewire::test('pages::daily-planner')
        ->call('suggestDailyPlan')
        ->assertSet('showAiSuggestions', false);

    // Still only 1 API call
    Http::assertSentCount(1);
});

test('suggestDailyPlan handles API error gracefully', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Activity::factory()->todo()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'server_error'], 500),
    ]);

    Livewire::test('pages::daily-planner')
        ->call('suggestDailyPlan')
        ->assertSet('aiLoading', false)
        ->assertSuccessful();
});

test('addSuggestionToPlan adds task to daily plan', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $task = Activity::factory()->todo()->create(['title' => 'Task sugerida']);

    Livewire::test('pages::daily-planner')
        ->set('aiSuggestions', [
            ['task_id' => $task->id, 'reason' => 'Important', 'priority_score' => 90],
        ])
        ->set('showAiSuggestions', true)
        ->call('addSuggestionToPlan', $task->id)
        ->assertCount('aiSuggestions', 0);

    $plan = DailyPlan::whereDate('date', now()->toDateString())->first();

    expect($plan->tasks()->where('activities.id', $task->id)->exists())->toBeTrue();
});

test('addAllSuggestionsToPlan adds all tasks and closes modal', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $task1 = Activity::factory()->todo()->create();
    $task2 = Activity::factory()->doing()->create();

    Livewire::test('pages::daily-planner')
        ->set('aiSuggestions', [
            ['task_id' => $task1->id, 'reason' => 'Reason 1', 'priority_score' => 90],
            ['task_id' => $task2->id, 'reason' => 'Reason 2', 'priority_score' => 70],
        ])
        ->set('showAiSuggestions', true)
        ->call('addAllSuggestionsToPlan')
        ->assertSet('showAiSuggestions', false)
        ->assertSet('aiSuggestions', []);

    $plan = DailyPlan::whereDate('date', now()->toDateString())->first();

    expect($plan->tasks()->whereIn('activities.id', [$task1->id, $task2->id])->count())->toBe(2);
});

// ── Inbox AI Integration ──────────────────────────────────────────────

test('AI analyze button is hidden when ai_enabled is false', function () {
    config(['soloboard.ai_enabled' => false]);

    Livewire::test('pages::inbox')
        ->assertDontSee('Analisar pendentes');
});

test('AI analyze button is visible when ai_enabled is true', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    Livewire::test('pages::inbox')
        ->assertSee('Analisar pendentes');
});

test('analyzeBacklog calls service and shows analysis', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $task = Activity::factory()->create(['title' => 'Task para analisar']);

    $analysis = [
        [
            'task_id' => $task->id,
            'action' => 'prioritize',
            'reason' => 'This task is important',
            'suggested_service_class' => 'emergency',
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

    Livewire::test('pages::inbox')
        ->call('analyzeBacklog')
        ->assertSet('showBacklogAnalysis', true)
        ->assertSet('aiLoading', false)
        ->assertCount('backlogAnalysis', 1);

    Http::assertSentCount(1);
});

test('analyzeBacklog does nothing when AI is disabled', function () {
    config(['soloboard.ai_enabled' => false]);

    Livewire::test('pages::inbox')
        ->call('analyzeBacklog')
        ->assertSet('showBacklogAnalysis', false)
        ->assertSet('backlogAnalysis', []);

    Http::assertNothingSent();
});

test('analyzeBacklog rate limiting prevents rapid API calls', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Activity::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => '[]'],
            ],
        ]),
    ]);

    // First call should succeed
    Livewire::test('pages::inbox')
        ->call('analyzeBacklog')
        ->assertSet('showBacklogAnalysis', true);

    Http::assertSentCount(1);

    // Second call should be rate limited
    Livewire::test('pages::inbox')
        ->call('analyzeBacklog')
        ->assertSet('showBacklogAnalysis', false);

    Http::assertSentCount(1);
});

test('analyzeBacklog handles API error gracefully', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Activity::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'server_error'], 500),
    ]);

    Livewire::test('pages::inbox')
        ->call('analyzeBacklog')
        ->assertSet('aiLoading', false)
        ->assertSuccessful();
});

test('applyBacklogSuggestion with prioritize action updates task', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $task = Activity::factory()->create(['title' => 'Task para priorizar']);

    Livewire::test('pages::inbox')
        ->set('backlogAnalysis', [
            [
                'task_id' => $task->id,
                'action' => 'prioritize',
                'reason' => 'Important task',
                'suggested_service_class' => 'emergency',
                'suggested_project' => null,
            ],
        ])
        ->set('showBacklogAnalysis', true)
        ->call('applyBacklogSuggestion', $task->id, 'prioritize')
        ->assertCount('backlogAnalysis', 0)
        // An Emergência is never applied straight from a suggestion: the
        // machine proposes the motivo, the human confirms it in the same
        // blocking modal every other surface defers to (issue #143).
        ->assertDispatched('open-emergency-modal', taskId: $task->id, reason: 'Important task');

    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Todo)
        ->and($task->service_class->value)->toBe('standard');
});

test('applyBacklogSuggestion applies a non-emergency service class directly', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $task = Activity::factory()->create(['title' => 'Task para priorizar']);

    Livewire::test('pages::inbox')
        ->set('backlogAnalysis', [
            [
                'task_id' => $task->id,
                'action' => 'prioritize',
                'reason' => 'Important task',
                'suggested_service_class' => 'intangible',
                'suggested_project' => null,
            ],
        ])
        ->set('showBacklogAnalysis', true)
        ->call('applyBacklogSuggestion', $task->id, 'prioritize')
        ->assertNotDispatched('open-emergency-modal');

    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Todo)
        ->and($task->service_class->value)->toBe('intangible');
});

test('applyBacklogSuggestion with prioritize action refuses fixed_date without due date', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $task = Activity::factory()->create([
        'title' => 'Task para priorizar',
        'service_class' => ServiceClass::Standard,
        'due_date' => null,
    ]);

    Livewire::test('pages::inbox')
        ->set('backlogAnalysis', [
            [
                'task_id' => $task->id,
                'action' => 'prioritize',
                'reason' => 'Important task',
                'suggested_service_class' => 'fixed_date',
                'suggested_project' => null,
            ],
        ])
        ->set('showBacklogAnalysis', true)
        ->call('applyBacklogSuggestion', $task->id, 'prioritize')
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return ($params['slots']['text'] ?? null) === FixedDateRequiresDueDateException::MESSAGE;
        });

    $task->refresh();

    expect($task->service_class->value)->toBe('standard');
});

test('applyBacklogSuggestion with archive action archives task', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $task = Activity::factory()->create(['title' => 'Task para arquivar']);

    Livewire::test('pages::inbox')
        ->set('backlogAnalysis', [
            [
                'task_id' => $task->id,
                'action' => 'archive',
                'reason' => 'Stale task',
                'suggested_service_class' => null,
                'suggested_project' => null,
            ],
        ])
        ->set('showBacklogAnalysis', true)
        ->call('applyBacklogSuggestion', $task->id, 'archive')
        ->assertCount('backlogAnalysis', 0);

    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Done)
        ->and($task->completed_at)->not->toBeNull();
});

test('dismissBacklogSuggestion removes suggestion from list', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $task1 = Activity::factory()->create();
    $task2 = Activity::factory()->create();

    Livewire::test('pages::inbox')
        ->set('backlogAnalysis', [
            ['task_id' => $task1->id, 'action' => 'prioritize', 'reason' => 'R1', 'suggested_service_class' => null, 'suggested_project' => null],
            ['task_id' => $task2->id, 'action' => 'archive', 'reason' => 'R2', 'suggested_service_class' => null, 'suggested_project' => null],
        ])
        ->set('showBacklogAnalysis', true)
        ->call('dismissBacklogSuggestion', $task1->id)
        ->assertCount('backlogAnalysis', 1)
        ->assertSet('showBacklogAnalysis', true);
});

test('dismissBacklogSuggestion closes modal when last suggestion dismissed', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
    ]);

    $task = Activity::factory()->create();

    Livewire::test('pages::inbox')
        ->set('backlogAnalysis', [
            ['task_id' => $task->id, 'action' => 'archive', 'reason' => 'R1', 'suggested_service_class' => null, 'suggested_project' => null],
        ])
        ->set('showBacklogAnalysis', true)
        ->call('dismissBacklogSuggestion', $task->id)
        ->assertCount('backlogAnalysis', 0)
        ->assertSet('showBacklogAnalysis', false);
});
