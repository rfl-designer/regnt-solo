<?php

use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\AiAssistantService;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** @var array<int, array{type: string, message: string, severity: string, action_url: string|null, action_label: string|null}> */
    public array $aiInsights = [];

    public bool $insightsLoading = false;

    public function mount(): void
    {
        if ($this->isAiEnabled()) {
            $this->loadInsights();
        }
    }

    /**
     * Get total deep work minutes for today.
     */
    #[Computed]
    public function deepWorkToday(): float
    {
        return TimeEntry::focusDurationMinutes(Carbon::today());
    }

    /**
     * Get tasks completed this week.
     */
    #[Computed]
    public function tasksCompletedThisWeek(): int
    {
        return Task::query()
            ->where('status', TaskStatus::Done)
            ->where('completed_at', '>=', Carbon::now()->startOfWeek())
            ->count();
    }

    /**
     * Get active tasks count (todo + doing).
     */
    #[Computed]
    public function activeTasksCount(): int
    {
        return Task::query()
            ->whereIn('status', [TaskStatus::Todo, TaskStatus::Doing])
            ->count();
    }

    /**
     * Get inbox tasks count.
     */
    #[Computed]
    public function inboxCount(): int
    {
        return Task::query()
            ->where('status', TaskStatus::Inbox)
            ->count();
    }

    /**
     * Calculate average time in each status for tasks completed in the last 30 days,
     * with trend indicators comparing to the previous 30-day period.
     *
     * @return array<string, array{label: string, avg_minutes: float, formatted: string, color: string, hex_color: string, prev_avg_minutes: float|null, prev_formatted: string|null, trend_direction: string|null, trend_pct: float|null}>
     */
    #[Computed]
    public function averageTimeByStatus(): array
    {
        $now = Carbon::now();

        $currentPeriodTasks = Task::query()
            ->where('status', TaskStatus::Done)
            ->where('completed_at', '>=', $now->copy()->subDays(30))
            ->with('statusChanges')
            ->get();

        $previousPeriodTasks = Task::query()
            ->where('status', TaskStatus::Done)
            ->where('completed_at', '>=', $now->copy()->subDays(60))
            ->where('completed_at', '<', $now->copy()->subDays(30))
            ->with('statusChanges')
            ->get();

        if ($currentPeriodTasks->isEmpty()) {
            return [];
        }

        $currentAverages = $this->calculateAveragesForTasks($currentPeriodTasks);
        $previousAverages = $this->calculateAveragesForTasks($previousPeriodTasks);

        $averages = [];

        foreach (TaskStatus::cases() as $status) {
            $statusValue = $status->value;

            if (! isset($currentAverages[$statusValue])) {
                continue;
            }

            $avgMinutes = $currentAverages[$statusValue];
            $prevAvgMinutes = $previousAverages[$statusValue] ?? null;

            $trendDirection = null;
            $trendPct = null;

            if ($prevAvgMinutes !== null && $prevAvgMinutes > 0) {
                $diff = $avgMinutes - $prevAvgMinutes;
                $trendPct = round(($diff / $prevAvgMinutes) * 100, 1);

                if ($trendPct < -1) {
                    $trendDirection = 'down';
                } elseif ($trendPct > 1) {
                    $trendDirection = 'up';
                } else {
                    $trendDirection = 'neutral';
                }
            }

            $averages[$statusValue] = [
                'label' => $status->label(),
                'avg_minutes' => round($avgMinutes, 1),
                'formatted' => $this->formatDuration($avgMinutes),
                'color' => $status->color(),
                'hex_color' => $status->hexColor(),
                'prev_avg_minutes' => $prevAvgMinutes !== null ? round($prevAvgMinutes, 1) : null,
                'prev_formatted' => $prevAvgMinutes !== null ? $this->formatDuration($prevAvgMinutes) : null,
                'trend_direction' => $trendDirection,
                'trend_pct' => $trendPct,
            ];
        }

        return $averages;
    }

    /**
     * Calculate average time in each status for a collection of tasks.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     * @return array<string, float>
     */
    private function calculateAveragesForTasks($tasks): array
    {
        $totals = [];
        $counts = [];

        foreach (TaskStatus::cases() as $status) {
            $totals[$status->value] = 0.0;
            $counts[$status->value] = 0;
        }

        foreach ($tasks as $task) {
            $timeInStatus = $task->time_in_status;

            foreach ($timeInStatus as $statusValue => $minutes) {
                if ($minutes > 0) {
                    $totals[$statusValue] += $minutes;
                    $counts[$statusValue]++;
                }
            }
        }

        $averages = [];

        foreach (TaskStatus::cases() as $status) {
            $count = $counts[$status->value];

            if ($count > 0) {
                $averages[$status->value] = $totals[$status->value] / $count;
            }
        }

        return $averages;
    }

    /**
     * Format minutes into a human-readable duration string.
     */
    public function formatDuration(float $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $totalMinutes = (int) round($minutes);
        $days = intdiv($totalMinutes, 1440);
        $hours = intdiv($totalMinutes % 1440, 60);
        $mins = $totalMinutes % 60;

        if ($days > 0) {
            return "{$days}d {$hours}h {$mins}m";
        }

        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }

        return "{$mins}m";
    }

    /**
     * Check if AI features are enabled and configured.
     */
    public function isAiEnabled(): bool
    {
        return app(AiAssistantService::class)->isEnabled();
    }

    /**
     * Load AI insights from cache or generate new ones.
     */
    public function loadInsights(): void
    {
        if (! $this->isAiEnabled()) {
            return;
        }

        $this->insightsLoading = true;

        try {
            $userId = auth()->id() ?? 'guest';
            $cacheKey = 'ai_insights_'.$userId;

            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                $this->aiInsights = $this->filterDismissedInsights($cached, $userId);

                return;
            }

            $weeklyData = $this->buildWeeklyData();

            if (empty($weeklyData)) {
                $this->aiInsights = [];

                return;
            }

            $service = app(AiAssistantService::class);
            $result = $service->detectPatterns($weeklyData);

            Cache::put(
                $cacheKey,
                $result,
                now()->addHours(config('soloboard.ai_insights_cache_hours', 24))
            );

            $this->aiInsights = $this->filterDismissedInsights($result, $userId);
        } catch (\Throwable $e) {
            $this->aiInsights = [];
        } finally {
            $this->insightsLoading = false;
        }
    }

    /**
     * Dismiss an insight and hide it for 7 days.
     */
    public function dismissInsight(int $index): void
    {
        if (! isset($this->aiInsights[$index])) {
            return;
        }

        $insight = $this->aiInsights[$index];
        $userId = auth()->id() ?? 'guest';
        $dismissKey = 'ai_insight_dismissed_'.$userId.'_'.md5($insight['message']);

        Cache::put($dismissKey, true, now()->addDays(7));

        unset($this->aiInsights[$index]);
        $this->aiInsights = array_values($this->aiInsights);
    }

    /**
     * Refresh insights by clearing cache and reloading.
     */
    public function refreshInsights(): void
    {
        if (! $this->isAiEnabled()) {
            return;
        }

        $userId = auth()->id() ?? 'guest';
        $rateLimitKey = 'ai_insights_refresh_'.$userId;

        if (Cache::has($rateLimitKey)) {
            Flux::toast(variant: 'warning', heading: 'Aguarde', text: 'Aguarde 1 hora antes de atualizar os insights novamente.');

            return;
        }

        $cacheKey = 'ai_insights_'.$userId;
        Cache::forget($cacheKey);

        $this->loadInsights();

        Cache::put($rateLimitKey, true, now()->addHour());
    }

    /**
     * Build weekly data from the database for pattern detection.
     *
     * @return array<string, mixed>
     */
    private function buildWeeklyData(): array
    {
        $today = Carbon::today();
        $weekAgo = $today->copy()->subDays(6);

        $tasksByStatus = Task::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $projects = Project::query()
            ->active()
            ->withMax('tasks', 'updated_at')
            ->get()
            ->map(fn (Project $project): array => [
                'name' => $project->name,
                'last_activity' => $project->tasks_max_updated_at,
            ])
            ->toArray();

        $timeEntries = TimeEntry::query()
            ->whereNotNull('stopped_at')
            ->whereDate('started_at', '>=', $weekAgo)
            ->whereDate('started_at', '<=', $today)
            ->get();

        $hoursPerDay = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            $dateString = $date->toDateString();
            $minutes = $timeEntries
                ->filter(fn (TimeEntry $entry): bool => $entry->started_at->toDateString() === $dateString)
                ->sum('duration_minutes');

            $hoursPerDay[$dateString] = round($minutes / 60, 2);
        }

        $staleBacklogTasks = Task::query()
            ->where('status', TaskStatus::Backlog)
            ->where('created_at', '<', Carbon::now()->subDays(14))
            ->count();

        $dailyPlans = DailyPlan::query()
            ->whereDate('date', '>=', $weekAgo)
            ->whereDate('date', '<=', $today)
            ->with('tasks')
            ->get()
            ->keyBy(fn (DailyPlan $plan): string => $plan->date->toDateString());

        $completionRates = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateString = $today->copy()->subDays($i)->toDateString();
            $completionRates[$dateString] = $dailyPlans->get($dateString)?->completionRate() ?? 0;
        }

        return [
            'tasks_by_status' => $tasksByStatus,
            'projects' => $projects,
            'hours_per_day' => $hoursPerDay,
            'stale_backlog_count' => $staleBacklogTasks,
            'completion_rates' => $completionRates,
        ];
    }

    /**
     * Filter out insights that have been dismissed by the user.
     *
     * @param  array<int, array{type: string, message: string, severity: string, action_url: string|null, action_label: string|null}>  $insights
     * @return array<int, array{type: string, message: string, severity: string, action_url: string|null, action_label: string|null}>
     */
    private function filterDismissedInsights(array $insights, int|string $userId): array
    {
        return array_values(array_filter($insights, function (array $insight) use ($userId): bool {
            $dismissKey = 'ai_insight_dismissed_'.$userId.'_'.md5($insight['message']);

            return ! Cache::has($dismissKey);
        }));
    }

    /**
     * Get the icon name for an insight type.
     */
    public function insightIcon(string $type): string
    {
        return match ($type) {
            'abandoned_project', 'project_abandoned' => 'archive-box-x-mark',
            'over_commitment', 'backlog_overload' => 'inbox-stack',
            'productive_hours', 'no_deep_work' => 'clock',
            'blocker' => 'exclamation-triangle',
            'positive_trend' => 'arrow-trending-up',
            default => 'light-bulb',
        };
    }

    /**
     * Get the callout variant for an insight severity.
     */
    public function insightVariant(string $severity): string
    {
        return match ($severity) {
            'warning' => 'warning',
            'critical', 'danger' => 'danger',
            default => 'secondary',
        };
    }

    #[On('task-updated')]
    #[On('task-created')]
    public function refreshMetrics(): void
    {
        unset(
            $this->averageTimeByStatus,
            $this->deepWorkToday,
            $this->tasksCompletedThisWeek,
            $this->activeTasksCount,
            $this->inboxCount
        );
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
    <flux:heading size="xl">Dashboard</flux:heading>

    {{-- AI Insights Section --}}
    @if ($this->isAiEnabled())
        @if ($insightsLoading)
            <div class="space-y-3">
                @for ($i = 0; $i < 2; $i++)
                    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4">
                        <div class="flex items-start gap-3">
                            <flux:skeleton class="size-5 shrink-0 rounded" />
                            <div class="flex-1 space-y-2">
                                <flux:skeleton class="h-4 w-3/4" />
                                <flux:skeleton class="h-3 w-full" />
                            </div>
                        </div>
                    </div>
                @endfor
            </div>
        @elseif (count($aiInsights) > 0)
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <flux:icon name="sparkles" class="size-5 text-purple-400" />
                        <flux:heading size="sm">AI Insights</flux:heading>
                    </div>
                    <flux:button
                        variant="ghost"
                        size="sm"
                        icon="arrow-path"
                        wire:click="refreshInsights"
                        wire:loading.attr="disabled"
                        wire:target="refreshInsights"
                    >
                        <span wire:loading.remove wire:target="refreshInsights">Atualizar</span>
                        <span wire:loading wire:target="refreshInsights">Atualizando...</span>
                    </flux:button>
                </div>

                @foreach ($aiInsights as $index => $insight)
                    <flux:callout
                        wire:key="insight-{{ $index }}"
                        :icon="$this->insightIcon($insight['type'] ?? '')"
                        :variant="$this->insightVariant($insight['severity'] ?? 'info')"
                        inline
                    >
                        <flux:callout.text>{{ $insight['message'] }}</flux:callout.text>

                        <x-slot name="actions">
                            @if (! empty($insight['action_url']))
                                <flux:button variant="subtle" size="sm" :href="$insight['action_url']">
                                    {{ $insight['action_label'] ?? 'Ver' }}
                                </flux:button>
                            @endif
                            <flux:button variant="ghost" size="sm" wire:click="dismissInsight({{ $index }})">
                                Ignorar
                            </flux:button>
                        </x-slot>
                    </flux:callout>
                @endforeach
            </div>
        @endif
    @endif

    <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {{-- Tasks Concluídas esta Semana --}}
        <a
            href="{{ route('kanban') }}?status=done"
            wire:navigate
            class="relative overflow-hidden rounded-xl border border-emerald-500/20 bg-zinc-800/50 p-5 transition hover:border-emerald-500/40"
        >
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-lg bg-emerald-500/10">
                    <flux:icon name="check-circle" class="size-5 text-emerald-400" />
                </div>
                <div class="min-w-0">
                    <flux:text class="text-xs text-zinc-400">Concluídas esta semana</flux:text>
                    @if ($this->tasksCompletedThisWeek > 0)
                        <flux:heading size="lg" class="truncate">{{ $this->tasksCompletedThisWeek }} {{ $this->tasksCompletedThisWeek === 1 ? 'task' : 'tasks' }}</flux:heading>
                    @else
                        <flux:text class="text-sm text-zinc-500">Nenhuma ainda</flux:text>
                    @endif
                </div>
            </div>
        </a>

        {{-- Inbox / Tasks Ativas --}}
        <a
            href="{{ route('inbox') }}"
            wire:navigate
            class="relative overflow-hidden rounded-xl border border-blue-500/20 bg-zinc-800/50 p-5 transition hover:border-blue-500/40"
        >
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-lg bg-blue-500/10">
                    <flux:icon name="inbox-stack" class="size-5 text-blue-400" />
                </div>
                <div class="min-w-0">
                    <flux:text class="text-xs text-zinc-400">Inbox</flux:text>
                    @if ($this->inboxCount > 0)
                        <flux:heading size="lg" class="truncate">{{ $this->inboxCount }} {{ $this->inboxCount === 1 ? 'task' : 'tasks' }}</flux:heading>
                    @else
                        <flux:text class="text-sm text-zinc-500">Inbox vazio ✨</flux:text>
                    @endif
                </div>
            </div>
            @if ($this->activeTasksCount > 0)
                <div class="mt-2 border-t border-zinc-700 pt-2">
                    <flux:text class="text-xs text-zinc-500">{{ $this->activeTasksCount }} {{ $this->activeTasksCount === 1 ? 'task ativa' : 'tasks ativas' }}</flux:text>
                </div>
            @endif
        </a>

        {{-- Foco Hoje --}}
        <div class="relative overflow-hidden rounded-xl border border-amber-500/20 bg-zinc-800/50 p-5">
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-lg bg-amber-500/10">
                    <span class="text-lg">🎯</span>
                </div>
                <div class="min-w-0">
                    <flux:text class="text-xs text-zinc-400">Foco hoje</flux:text>
                    @if ($this->deepWorkToday > 0)
                        <flux:heading size="lg" class="truncate">{{ $this->formatDuration($this->deepWorkToday) }}</flux:heading>
                    @else
                        <flux:text class="text-sm text-zinc-500">Inicie uma sessão de foco</flux:text>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Average Time by Status Section --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
        <div class="mb-4 flex items-center gap-2">
            <flux:icon name="chart-bar" class="size-5 text-zinc-400" />
            <flux:heading size="sm">Tempo médio por status</flux:heading>
            <flux:text class="text-xs text-zinc-500">(últimos 30 dias)</flux:text>
        </div>

        @if (count($this->averageTimeByStatus) > 0)
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($this->averageTimeByStatus as $statusValue => $data)
                    <div
                        class="rounded-lg border border-zinc-700 bg-zinc-900/50 p-3"
                        @if ($data['prev_formatted'])
                            x-data
                            x-tooltip.raw="Período anterior: {{ $data['prev_formatted'] }}"
                        @endif
                    >
                        <div class="mb-1 flex items-center gap-1.5">
                            <div class="size-2.5 rounded-full" style="background-color: {{ $data['hex_color'] }};"></div>
                            <flux:text class="text-xs text-zinc-400">{{ $data['label'] }}</flux:text>
                        </div>
                        <div class="flex flex-wrap items-center gap-1 sm:gap-2">
                            <flux:heading size="sm">{{ $data['formatted'] }}</flux:heading>
                            @if ($data['trend_direction'] === 'down')
                                <span class="flex items-center gap-0.5 text-xs font-medium text-emerald-400" title="Melhorou {{ abs($data['trend_pct']) }}% vs período anterior">
                                    <flux:icon name="arrow-trending-down" class="size-3.5" />
                                    <span>{{ abs($data['trend_pct']) }}%</span>
                                </span>
                            @elseif ($data['trend_direction'] === 'up')
                                <span class="flex items-center gap-0.5 text-xs font-medium text-red-400" title="Piorou {{ abs($data['trend_pct']) }}% vs período anterior">
                                    <flux:icon name="arrow-trending-up" class="size-3.5" />
                                    <span>+{{ abs($data['trend_pct']) }}%</span>
                                </span>
                            @elseif ($data['trend_direction'] === 'neutral')
                                <span class="flex items-center gap-0.5 text-xs font-medium text-zinc-500" title="Estável vs período anterior">
                                    <flux:icon name="minus" class="size-3.5" />
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex items-center justify-center py-6 text-center">
                <div>
                    <flux:icon name="clock" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Sem dados suficientes</flux:text>
                    <flux:text class="text-xs text-zinc-600">Conclua tasks para ver métricas de tempo.</flux:text>
                </div>
            </div>
        @endif
    </div>

    <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
        <div class="flex h-full min-h-48 items-center justify-center text-zinc-500">
            <div class="text-center">
                <flux:icon name="squares-2x2" class="mx-auto mb-3 size-12" />
                <flux:heading size="lg">Bem-vindo ao SoloBoard</flux:heading>
                <flux:text class="mt-1">Seu painel de gestão pessoal de projetos.</flux:text>
            </div>
        </div>
    </div>
</div>
