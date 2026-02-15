<?php

namespace App\Mcp\Resources;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
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

    protected string $description = 'Provides a comprehensive overview of the current SoloBoard state including active projects with task counts, running timer, overdue tasks, and hours worked today.';

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        $projects = Project::query()
            ->active()
            ->ordered()
            ->get()
            ->map(function (Project $project) {
                $taskCounts = [];
                foreach (TaskStatus::cases() as $status) {
                    $count = $project->tasks()->where('status', $status)->count();
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
            ->with('task.project')
            ->running()
            ->latest('started_at')
            ->first();

        $timer = null;
        if ($runningEntry) {
            $timer = [
                'task_id' => $runningEntry->task_id,
                'task_title' => $runningEntry->task->title,
                'project' => $runningEntry->task->project?->name,
                'started_at' => $runningEntry->started_at->toDateTimeString(),
                'duration_minutes' => $runningEntry->duration_minutes,
            ];
        }

        $overdueTasks = Task::query()
            ->with('project')
            ->overdue()
            ->orderBy('due_date')
            ->get()
            ->map(fn (Task $task) => [
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

        $data = [
            'active_projects' => $projects,
            'running_timer' => $timer,
            'overdue_tasks' => $overdueTasks,
            'overdue_count' => count($overdueTasks),
            'hours_worked_today' => $hoursToday,
            'minutes_worked_today' => round($minutesToday, 0),
        ];

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }
}
