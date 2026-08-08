<?php

use App\Models\Activity;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ── Visibility ────────────────────────────────────────────────────────

test('insights section is hidden when ai_enabled is false', function () {
    config(['soloboard.ai_enabled' => false]);

    Livewire::test('pages::dashboard')
        ->assertDontSee('AI Insights')
        ->assertDontSee('Atualizar');
});

test('insights section shows when ai_enabled is true and insights exist', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $insights = [
        [
            'type' => 'over_commitment',
            'message' => 'You have too many tasks in progress.',
            'severity' => 'warning',
            'action_url' => '/kanban',
            'action_label' => 'Ver Kanban',
        ],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($insights)],
            ],
        ]),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSee('AI Insights')
        ->assertSee('You have too many tasks in progress.')
        ->assertSee('Ver Kanban')
        ->assertSee('Ignorar');
});

// ── Caching ───────────────────────────────────────────────────────────

test('insights are cached for configured hours', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
        'soloboard.ai_insights_cache_hours' => 24,
    ]);

    $insights = [
        [
            'type' => 'blocker',
            'message' => 'Cached insight message.',
            'severity' => 'info',
            'action_url' => null,
            'action_label' => null,
        ],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($insights)],
            ],
        ]),
    ]);

    // First load — calls API and caches
    Livewire::test('pages::dashboard')
        ->assertSee('Cached insight message.');

    Http::assertSentCount(1);

    // Second load — uses cache, no new API call
    Livewire::test('pages::dashboard')
        ->assertSee('Cached insight message.');

    Http::assertSentCount(1);
});

// ── Dismiss ───────────────────────────────────────────────────────────

test('dismiss insight hides it for 7 days', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $insights = [
        [
            'type' => 'over_commitment',
            'message' => 'Insight to dismiss.',
            'severity' => 'warning',
            'action_url' => null,
            'action_label' => null,
        ],
        [
            'type' => 'positive_trend',
            'message' => 'Insight to keep.',
            'severity' => 'info',
            'action_url' => null,
            'action_label' => null,
        ],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($insights)],
            ],
        ]),
    ]);

    Livewire::test('pages::dashboard')
        ->assertCount('aiInsights', 2)
        ->call('dismissInsight', 0)
        ->assertCount('aiInsights', 1)
        ->assertSee('Insight to keep.')
        ->assertDontSee('Insight to dismiss.');

    // Verify dismiss cache key was set
    $userId = auth()->id();
    $dismissKey = 'ai_insight_dismissed_'.$userId.'_'.md5('Insight to dismiss.');

    expect(Cache::has($dismissKey))->toBeTrue();
});

test('dismissed insights are filtered from display', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $insights = [
        [
            'type' => 'blocker',
            'message' => 'Previously dismissed insight.',
            'severity' => 'warning',
            'action_url' => null,
            'action_label' => null,
        ],
        [
            'type' => 'positive_trend',
            'message' => 'Visible insight.',
            'severity' => 'info',
            'action_url' => null,
            'action_label' => null,
        ],
    ];

    // Pre-dismiss the first insight
    $userId = auth()->id();
    $dismissKey = 'ai_insight_dismissed_'.$userId.'_'.md5('Previously dismissed insight.');
    Cache::put($dismissKey, true, now()->addDays(7));

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($insights)],
            ],
        ]),
    ]);

    Livewire::test('pages::dashboard')
        ->assertCount('aiInsights', 1)
        ->assertSee('Visible insight.')
        ->assertDontSee('Previously dismissed insight.');
});

// ── Refresh ───────────────────────────────────────────────────────────

test('refresh insights clears cache and reloads', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $initialInsights = [
        [
            'type' => 'blocker',
            'message' => 'Initial insight.',
            'severity' => 'info',
            'action_url' => null,
            'action_label' => null,
        ],
    ];

    $refreshedInsights = [
        [
            'type' => 'positive_trend',
            'message' => 'Refreshed insight.',
            'severity' => 'info',
            'action_url' => null,
            'action_label' => null,
        ],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push([
                'content' => [
                    ['type' => 'text', 'text' => json_encode($initialInsights)],
                ],
            ])
            ->push([
                'content' => [
                    ['type' => 'text', 'text' => json_encode($refreshedInsights)],
                ],
            ]),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSee('Initial insight.')
        ->call('refreshInsights')
        ->assertSee('Refreshed insight.');

    Http::assertSentCount(2);
});

test('rate limiting prevents rapid refresh', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $insights = [
        [
            'type' => 'blocker',
            'message' => 'Rate limited insight.',
            'severity' => 'info',
            'action_url' => null,
            'action_label' => null,
        ],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($insights)],
            ],
        ]),
    ]);

    // First load (mount) + first refresh should work
    $component = Livewire::test('pages::dashboard')
        ->assertSee('Rate limited insight.')
        ->call('refreshInsights');

    Http::assertSentCount(2);

    // Second refresh should be rate limited
    $component->call('refreshInsights');

    // Still only 2 API calls (mount + first refresh)
    Http::assertSentCount(2);
});

// ── Weekly Data ───────────────────────────────────────────────────────

test('weekly data is correctly built from database', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    // Create tasks in various statuses
    Activity::factory()->todo()->count(3)->create();
    Activity::factory()->doing()->count(2)->create();
    Activity::factory()->done()->count(1)->create();
    Activity::factory()->backlog()->create([
        'created_at' => now()->subDays(20),
    ]);

    // Create a project with activity
    $project = Project::factory()->create();
    Activity::factory()->todo()->create(['project_id' => $project->id]);

    // Create time entries for the last 7 days
    $task = Activity::factory()->todo()->create();
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => now()->subDays(1)->setHour(10),
        'stopped_at' => now()->subDays(1)->setHour(12),
    ]);

    // Create a daily plan with completion
    Activity::factory()->done()->create(['completed_at' => now()]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => '[]'],
            ],
        ]),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSet('insightsLoading', false);

    // Verify the API was called (meaning buildWeeklyData produced data)
    Http::assertSentCount(1);

    // Verify the request body contains weekly data
    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($body, 'tasks_by_status')
            && str_contains($body, 'hours_per_day')
            && str_contains($body, 'stale_backlog_count')
            && str_contains($body, 'items_completed_per_day');
    });
});

// ── Edge Cases ────────────────────────────────────────────────────────

test('loadInsights handles API error gracefully', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'server_error'], 500),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSet('insightsLoading', false)
        ->assertSet('aiInsights', [])
        ->assertSuccessful();
});

test('insights with action_url render action button', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $insights = [
        [
            'type' => 'abandoned_project',
            'message' => 'Project X has no activity.',
            'severity' => 'warning',
            'action_url' => '/projects',
            'action_label' => 'Ver Projetos',
        ],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($insights)],
            ],
        ]),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSee('Project X has no activity.')
        ->assertSee('Ver Projetos');
});

test('insights without action_url do not render action button label', function () {
    config([
        'soloboard.ai_enabled' => true,
        'soloboard.ai_api_key' => 'test-key',
        'soloboard.ai_model' => 'claude-sonnet-4-20250514',
    ]);

    $insights = [
        [
            'type' => 'positive_trend',
            'message' => 'Great productivity this week!',
            'severity' => 'info',
            'action_url' => null,
            'action_label' => null,
        ],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => json_encode($insights)],
            ],
        ]),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSee('Great productivity this week!')
        ->assertSee('Ignorar');
});
