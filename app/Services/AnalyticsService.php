<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatusChange;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class AnalyticsService
{
    /**
     * Generate a contribution heatmap grid based on tracked hours and completed tasks.
     *
     * Level calculation: 0 = no activity, 1 = light (<1h), 2 = moderate (1-2h), 3 = active (2-4h), 4 = very active (4h+).
     *
     * @return array<int, array{date: string, level: int, hours: float, tasks_completed: int}>
     */
    public function contributionHeatmap(int $weeks = 12): array
    {
        $startDate = Carbon::today()->subWeeks($weeks)->startOfDay();
        $endDate = Carbon::today()->endOfDay();

        $hoursByDate = TimeEntry::query()
            ->whereNotNull('stopped_at')
            ->where('started_at', '>=', $startDate)
            ->selectRaw('date(started_at) as date, sum(cast((julianday(stopped_at) - julianday(started_at)) * 24 as real)) as total_hours')
            ->groupByRaw('date(started_at)')
            ->pluck('total_hours', 'date');

        $tasksByDate = Task::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $startDate)
            ->selectRaw('date(completed_at) as date, count(*) as total')
            ->groupByRaw('date(completed_at)')
            ->pluck('total', 'date');

        $heatmap = [];

        foreach (CarbonPeriod::create($startDate, $endDate) as $day) {
            $dateKey = $day->toDateString();
            $hours = round((float) ($hoursByDate[$dateKey] ?? 0), 2);
            $tasksCompleted = (int) ($tasksByDate[$dateKey] ?? 0);

            $heatmap[] = [
                'date' => $dateKey,
                'level' => $this->calculateHeatmapLevel($hours),
                'hours' => $hours,
                'tasks_completed' => $tasksCompleted,
            ];
        }

        return $heatmap;
    }

    /**
     * Calculate average cycle time (minutes from first status change to done) per day.
     *
     * @return array<int, array{date: string, avg_minutes: float, project_name: string|null}>
     */
    public function cycleTime(string $period = '30d', ?int $projectId = null): array
    {
        $since = $this->parsePeriod($period);

        // Get the first status change per task (the start of the cycle)
        $firstChanges = TaskStatusChange::query()
            ->selectRaw('task_id, min(changed_at) as first_change_at')
            ->groupBy('task_id');

        // Get the "to done" status change per task (the end of the cycle)
        $doneChanges = TaskStatusChange::query()
            ->where('to_status', TaskStatus::Done)
            ->where('changed_at', '>=', $since)
            ->when($projectId !== null, function ($query) use ($projectId): void {
                $query->whereHas('task', fn ($q) => $q->where('project_id', $projectId));
            })
            ->with('task.project')
            ->get();

        $firstChangeMap = TaskStatusChange::query()
            ->whereIn('task_id', $doneChanges->pluck('task_id'))
            ->selectRaw('task_id, min(changed_at) as first_change_at')
            ->groupBy('task_id')
            ->pluck('first_change_at', 'task_id')
            ->map(fn (string $date): Carbon => Carbon::parse($date));

        /** @var Collection<string, Collection<int, array{minutes: float, project_name: string|null}>> $grouped */
        $grouped = $doneChanges
            ->filter(fn (TaskStatusChange $change): bool => $firstChangeMap->has($change->task_id))
            ->map(function (TaskStatusChange $change) use ($firstChangeMap): array {
                $firstChange = $firstChangeMap[$change->task_id];

                return [
                    'date' => $change->changed_at->toDateString(),
                    'minutes' => $firstChange->diffInMinutes($change->changed_at),
                    'project_name' => $change->task?->project?->name,
                ];
            })
            ->groupBy('date');

        return $grouped->map(fn (Collection $items, string $date): array => [
            'date' => $date,
            'avg_minutes' => round($items->avg('minutes'), 2),
            'project_name' => $projectId !== null ? $items->first()['project_name'] : null,
        ])->sortKeys()->values()->all();
    }

    /**
     * Calculate the ratio of focus session time to total tracked time.
     *
     * @return float 0.0 to 1.0
     */
    public function focusRatio(string $period = '7d'): float
    {
        $since = $this->parsePeriod($period);

        $totals = TimeEntry::query()
            ->whereNotNull('stopped_at')
            ->where('started_at', '>=', $since)
            ->selectRaw('
                sum(cast((julianday(stopped_at) - julianday(started_at)) * 24 * 60 as real)) as total_minutes,
                sum(case when is_focus_session = 1 then cast((julianday(stopped_at) - julianday(started_at)) * 24 * 60 as real) else 0 end) as focus_minutes
            ')
            ->first();

        $totalMinutes = (float) ($totals->total_minutes ?? 0);
        $focusMinutes = (float) ($totals->focus_minutes ?? 0);

        if ($totalMinutes === 0.0) {
            return 0.0;
        }

        return round($focusMinutes / $totalMinutes, 4);
    }

    /**
     * Calculate health scores for all active projects.
     *
     * Score = 100 - (stale_penalty + overdue_penalty + inactivity_penalty).
     *
     * @return array<int, array{project: string, project_id: int, score: int, factors: array{stale_tasks: int, overdue_tasks: int, total_tasks: int, days_since_last_activity: int, stale_penalty: float, overdue_penalty: float, inactivity_penalty: float}}>
     */
    public function projectHealthScores(): array
    {
        $projects = Project::query()
            ->active()
            ->with(['tasks' => fn ($q) => $q->where('status', '!=', TaskStatus::Done)])
            ->get();

        $now = Carbon::now();
        $staleThreshold = $now->copy()->subDays(7);

        return $projects->map(function (Project $project) use ($now, $staleThreshold): array {
            $activeTasks = $project->tasks;
            $totalTasks = $activeTasks->count();

            if ($totalTasks === 0) {
                return [
                    'project' => $project->name,
                    'project_id' => $project->id,
                    'score' => 100,
                    'factors' => [
                        'stale_tasks' => 0,
                        'overdue_tasks' => 0,
                        'total_tasks' => 0,
                        'days_since_last_activity' => 0,
                        'stale_penalty' => 0.0,
                        'overdue_penalty' => 0.0,
                        'inactivity_penalty' => 0.0,
                    ],
                ];
            }

            $staleTasks = $activeTasks->filter(
                fn (Task $task): bool => $task->updated_at->lt($staleThreshold)
            )->count();

            $overdueTasks = $activeTasks->filter(
                fn (Task $task): bool => $task->isOverdue()
            )->count();

            $lastActivity = $this->lastProjectActivityDate($project);
            $daysSinceLastActivity = $lastActivity !== null
                ? (int) $lastActivity->diffInDays($now)
                : 30;

            $stalePenalty = round(($staleTasks / $totalTasks) * 40, 2);
            $overduePenalty = round(($overdueTasks / $totalTasks) * 30, 2);
            $inactivityPenalty = min($daysSinceLastActivity * 2, 30);

            $score = (int) max(0, min(100, 100 - ($stalePenalty + $overduePenalty + $inactivityPenalty)));

            return [
                'project' => $project->name,
                'project_id' => $project->id,
                'score' => $score,
                'factors' => [
                    'stale_tasks' => $staleTasks,
                    'overdue_tasks' => $overdueTasks,
                    'total_tasks' => $totalTasks,
                    'days_since_last_activity' => $daysSinceLastActivity,
                    'stale_penalty' => $stalePenalty,
                    'overdue_penalty' => $overduePenalty,
                    'inactivity_penalty' => (float) $inactivityPenalty,
                ],
            ];
        })->sortBy('score')->values()->all();
    }

    /**
     * Track tasks completed vs created per week over a period.
     *
     * @return array<int, array{week_start: string, completed_count: int, created_count: int}>
     */
    public function velocityTrend(int $weeks = 12): array
    {
        $startDate = Carbon::today()->subWeeks($weeks)->startOfWeek();

        $completedByWeek = Task::query()
            ->where('status', TaskStatus::Done)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $startDate)
            ->get()
            ->groupBy(fn (Task $task): string => $task->completed_at->startOfWeek()->toDateString())
            ->map(fn (Collection $tasks): int => $tasks->count());

        $createdByWeek = Task::query()
            ->where('created_at', '>=', $startDate)
            ->get()
            ->groupBy(fn (Task $task): string => $task->created_at->startOfWeek()->toDateString())
            ->map(fn (Collection $tasks): int => $tasks->count());

        $trend = [];
        $current = $startDate->copy();

        while ($current->lte(Carbon::today())) {
            $weekStart = $current->toDateString();

            $trend[] = [
                'week_start' => $weekStart,
                'completed_count' => $completedByWeek[$weekStart] ?? 0,
                'created_count' => $createdByWeek[$weekStart] ?? 0,
            ];

            $current->addWeek();
        }

        return $trend;
    }

    /**
     * Calculate current and best activity/focus streaks.
     *
     * @return array{current: int, best: int, focus_current: int, focus_best: int}
     */
    public function streaks(): array
    {
        $activityDates = $this->getActivityDates();
        $focusDates = $this->getFocusDates();

        return [
            'current' => $this->calculateCurrentStreak($activityDates),
            'best' => $this->calculateBestStreak($activityDates),
            'focus_current' => $this->calculateCurrentStreak($focusDates),
            'focus_best' => $this->calculateBestStreak($focusDates),
        ];
    }

    /**
     * Analyze productivity patterns by day of week and hour of day.
     *
     * @return array{best_days: array<int, string>, best_hours: array<int, int>, avg_by_day: array<string, float>, avg_by_hour: array<int, float>}
     */
    public function productivityPatterns(int $weeks = 4): array
    {
        $since = Carbon::today()->subWeeks($weeks);

        $entries = TimeEntry::query()
            ->whereNotNull('stopped_at')
            ->where('started_at', '>=', $since)
            ->get(['started_at', 'stopped_at']);

        $hoursByDay = [];
        $hoursByHour = [];

        foreach ($entries as $entry) {
            $dayName = $entry->started_at->format('l');
            $hour = (int) $entry->started_at->format('G');
            $durationHours = $entry->started_at->diffInMinutes($entry->stopped_at) / 60;

            $hoursByDay[$dayName] = ($hoursByDay[$dayName] ?? 0) + $durationHours;
            $hoursByHour[$hour] = ($hoursByHour[$hour] ?? 0) + $durationHours;
        }

        $dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        // Average by number of occurrences of each day in the period
        $avgByDay = [];
        foreach ($dayOrder as $day) {
            $occurrences = $this->countDayOccurrences($since, Carbon::today(), $day);
            $avgByDay[$day] = $occurrences > 0
                ? round(($hoursByDay[$day] ?? 0) / $occurrences, 2)
                : 0.0;
        }

        // Average by number of weeks in the period
        $avgByHour = [];
        for ($h = 0; $h < 24; $h++) {
            $avgByHour[$h] = $weeks > 0
                ? round(($hoursByHour[$h] ?? 0) / $weeks, 2)
                : 0.0;
        }

        // Top 2 days by average hours
        $sortedDays = $avgByDay;
        arsort($sortedDays);
        $bestDays = array_values(array_slice(array_keys($sortedDays), 0, 2));

        // Top 3 hours by average hours
        $sortedHours = $avgByHour;
        arsort($sortedHours);
        $bestHours = array_values(array_map('intval', array_slice(array_keys($sortedHours), 0, 3)));

        return [
            'best_days' => $bestDays,
            'best_hours' => $bestHours,
            'avg_by_day' => $avgByDay,
            'avg_by_hour' => $avgByHour,
        ];
    }

    /**
     * Parse a period string (e.g., '7d', '12w', '6m') into a Carbon start date.
     */
    private function parsePeriod(string $period): Carbon
    {
        preg_match('/^(\d+)([dwm])$/', $period, $matches);

        if (count($matches) !== 3) {
            return Carbon::today()->subDays(30);
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        return match ($unit) {
            'd' => Carbon::today()->subDays($value),
            'w' => Carbon::today()->subWeeks($value),
            'm' => Carbon::today()->subMonths($value),
            default => Carbon::today()->subDays(30),
        };
    }

    /**
     * Calculate the heatmap level based on hours tracked.
     *
     * 0 = no activity, 1 = light (<1h), 2 = moderate (1-2h), 3 = active (2-4h), 4 = very active (4h+).
     */
    private function calculateHeatmapLevel(float $hours): int
    {
        if ($hours <= 0) {
            return 0;
        }

        if ($hours < 1) {
            return 1;
        }

        if ($hours < 2) {
            return 2;
        }

        if ($hours < 4) {
            return 3;
        }

        return 4;
    }

    /**
     * Get the most recent activity date for a project.
     *
     * Checks time entries, task completions, and status changes.
     */
    private function lastProjectActivityDate(Project $project): ?Carbon
    {
        $taskIds = $project->tasks->pluck('id');

        if ($taskIds->isEmpty()) {
            return null;
        }

        $lastTimeEntry = TimeEntry::query()
            ->whereIn('task_id', $taskIds)
            ->max('started_at');

        $lastCompletion = Task::query()
            ->where('project_id', $project->id)
            ->max('completed_at');

        $lastStatusChange = TaskStatusChange::query()
            ->whereIn('task_id', $taskIds)
            ->max('changed_at');

        $dates = collect([$lastTimeEntry, $lastCompletion, $lastStatusChange])
            ->filter()
            ->map(fn (string $date): Carbon => Carbon::parse($date));

        return $dates->isNotEmpty() ? $dates->max() : null;
    }

    /**
     * Get unique dates with any activity (time entry started or task completed).
     *
     * @return array<int, string>
     */
    private function getActivityDates(): array
    {
        $timeEntryDates = TimeEntry::query()
            ->whereNotNull('stopped_at')
            ->selectRaw('distinct date(started_at) as date')
            ->pluck('date');

        $completionDates = Task::query()
            ->whereNotNull('completed_at')
            ->selectRaw('distinct date(completed_at) as date')
            ->pluck('date');

        return $timeEntryDates
            ->merge($completionDates)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Get unique dates with 2+ hours of focus sessions.
     *
     * @return array<int, string>
     */
    private function getFocusDates(): array
    {
        return TimeEntry::query()
            ->where('is_focus_session', true)
            ->whereNotNull('stopped_at')
            ->selectRaw('date(started_at) as date, sum(cast((julianday(stopped_at) - julianday(started_at)) * 24 as real)) as total_hours')
            ->groupByRaw('date(started_at)')
            ->havingRaw('total_hours >= 2')
            ->pluck('date')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Calculate the current consecutive day streak ending today.
     *
     * @param  array<int, string>  $dates
     */
    private function calculateCurrentStreak(array $dates): int
    {
        if (empty($dates)) {
            return 0;
        }

        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        // Current streak must include today or yesterday to be active
        $lastDate = end($dates);
        if ($lastDate !== $today && $lastDate !== $yesterday) {
            return 0;
        }

        $streak = 1;
        $checkDate = Carbon::parse($lastDate);

        for ($i = count($dates) - 2; $i >= 0; $i--) {
            $expectedPrevious = $checkDate->copy()->subDay()->toDateString();

            if ($dates[$i] === $expectedPrevious) {
                $streak++;
                $checkDate = Carbon::parse($dates[$i]);
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Calculate the best (longest) consecutive day streak from a set of dates.
     *
     * @param  array<int, string>  $dates
     */
    private function calculateBestStreak(array $dates): int
    {
        if (empty($dates)) {
            return 0;
        }

        $best = 1;
        $current = 1;

        for ($i = 1; $i < count($dates); $i++) {
            $expected = Carbon::parse($dates[$i - 1])->addDay()->toDateString();

            if ($dates[$i] === $expected) {
                $current++;
                $best = max($best, $current);
            } else {
                $current = 1;
            }
        }

        return $best;
    }

    /**
     * Count how many times a specific day of the week occurs between two dates.
     */
    private function countDayOccurrences(Carbon $start, Carbon $end, string $dayName): int
    {
        $count = 0;
        $current = $start->copy();

        // Fast-forward to the first occurrence of the target day
        while ($current->format('l') !== $dayName && $current->lte($end)) {
            $current->addDay();
        }

        while ($current->lte($end)) {
            $count++;
            $current->addWeek();
        }

        return $count;
    }
}
