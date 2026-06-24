<?php

namespace App\Mcp\Resources;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Annotations\Priority;
use Laravel\Mcp\Server\Resource;

#[Priority(0.8)]
class ProjectOverviewResource extends Resource
{
    protected string $uri = 'soloboard://overview';

    protected string $description = 'Provides a comprehensive overview of the current SoloBoard state including active projects with task counts, active features, running timer, overdue tasks, and hours worked today.';

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        $withCounts = [];
        foreach (ActivityStatus::cases() as $status) {
            $withCounts["activities as tasks_{$status->value}_count"] = fn ($q) => $q->where('status', $status);
        }

        $projects = Project::query()
            ->active()
            ->ordered()
            ->withCount($withCounts)
            ->get()
            ->map(function (Project $project) {
                $taskCounts = [];
                foreach (ActivityStatus::cases() as $status) {
                    $count = (int) $project->{"tasks_{$status->value}_count"};
                    if ($count > 0) {
                        $taskCounts[$status->value] = $count;
                    }
                }

                return [
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'emoji' => $project->emoji,
                    'task_counts' => $taskCounts,
                ];
            })->all();

        $runningEntry = TimeEntry::query()
            ->with('activity.project')
            ->running()
            ->latest('started_at')
            ->first();

        $timer = null;
        if ($runningEntry) {
            $timer = [
                'task_id' => $runningEntry->activity_id,
                'task_title' => $runningEntry->activity->title,
                'project' => $runningEntry->activity->project?->name,
                'started_at' => $runningEntry->started_at->toDateTimeString(),
                'duration_minutes' => $runningEntry->duration_minutes,
            ];
        }

        $overdueTasks = Activity::query()
            ->with('project')
            ->overdue()
            ->orderBy('due_date')
            ->get()
            ->map(fn (Activity $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'due_date' => $task->due_date->toDateString(),
                'project' => $task->project?->name,
                'priority' => $task->priority->value,
            ])->all();

        $todayEntries = TimeEntry::query()
            ->forDate(Carbon::today())
            ->get();

        $minutesToday = $todayEntries->sum(fn (TimeEntry $entry) => $entry->duration_minutes);
        $hoursToday = round($minutesToday / 60, 2);

        // Get active features (not done)
        $activeFeatures = Activity::query()->epics()
            ->with(['project', 'children'])
            ->get()
            ->filter(fn (Activity $f) => $f->status->value !== 'done')
            ->take(10)
            ->map(fn (Activity $feature) => [
                'id' => $feature->id,
                'title' => $feature->title,
                'slug' => $feature->slug,
                'status' => $feature->status->value,
                'priority' => $feature->priority->value,
                'progress' => $feature->progress,
                'project' => $feature->project?->name,
                'tasks_count' => $feature->tasksCount(),
                'is_running' => $feature->isRunning(),
            ])->values()->all();

        $data = [
            'active_projects' => $projects,
            'active_features' => $activeFeatures,
            'running_timer' => $timer,
            'overdue_tasks' => $overdueTasks,
            'overdue_count' => count($overdueTasks),
            'hours_worked_today' => $hoursToday,
            'minutes_worked_today' => round($minutesToday, 0),
        ];

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }
}
