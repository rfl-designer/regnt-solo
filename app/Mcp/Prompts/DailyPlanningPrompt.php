<?php

namespace App\Mcp\Prompts;

use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class DailyPlanningPrompt extends Prompt
{
    protected string $description = 'Helps plan the developer\'s day based on current tasks, priorities, and workload. Provides context about overdue items, in-progress work, and suggests a focused plan.';

    /**
     * Get the prompt's arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'focus_project',
                description: 'Optional project slug to focus the planning on a specific project.',
                required: false,
            ),
        ];
    }

    /**
     * Handle the prompt request.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        $focusProject = $request->string('focus_project');

        $overdueTasks = Task::query()->overdue()->with('project')->get();
        $doingTasks = Task::query()->byStatus(TaskStatus::Doing)->with('project')->get();
        $todoTasks = Task::query()->byStatus(TaskStatus::Todo)->with('project')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END")
            ->limit(10)
            ->get();

        $todayPlan = DailyPlan::query()->whereDate('date', Carbon::today())->first();
        $plannedTasks = $todayPlan ? $todayPlan->tasks()->with('project')->get() : collect();

        $minutesToday = TimeEntry::query()
            ->forDate(Carbon::today())
            ->get()
            ->sum(fn (TimeEntry $e) => $e->duration_minutes);

        $context = "## Current State\n";
        $context .= '- Date: '.Carbon::today()->toDateString().' ('.Carbon::today()->format('l').")\n";
        $context .= '- Hours worked today: '.round($minutesToday / 60, 1)."h\n";
        $context .= '- Overdue tasks: '.$overdueTasks->count()."\n";
        $context .= '- Tasks in progress: '.$doingTasks->count()."\n";

        if ($focusProject) {
            $project = Project::query()->where('slug', $focusProject)->first();
            if ($project) {
                $context .= "- Focus project: {$project->emoji} {$project->name}\n";
            }
        }

        if ($overdueTasks->isNotEmpty()) {
            $context .= "\n## Overdue Tasks (URGENT)\n";
            foreach ($overdueTasks as $task) {
                $context .= "- [{$task->priority->value}] {$task->title} (due: {$task->due_date->toDateString()})";
                if ($task->project) {
                    $context .= " [{$task->project->name}]";
                }
                $context .= "\n";
            }
        }

        if ($doingTasks->isNotEmpty()) {
            $context .= "\n## In Progress\n";
            foreach ($doingTasks as $task) {
                $context .= "- {$task->title}";
                if ($task->project) {
                    $context .= " [{$task->project->name}]";
                }
                $context .= "\n";
            }
        }

        if ($todoTasks->isNotEmpty()) {
            $context .= "\n## Top Priority Todo\n";
            foreach ($todoTasks as $task) {
                $context .= "- [{$task->priority->value}] {$task->title}";
                if ($task->project) {
                    $context .= " [{$task->project->name}]";
                }
                if ($task->due_date) {
                    $context .= " (due: {$task->due_date->toDateString()})";
                }
                $context .= "\n";
            }
        }

        if ($plannedTasks->isNotEmpty()) {
            $context .= "\n## Already Planned for Today\n";
            foreach ($plannedTasks as $task) {
                $done = $task->pivot->completed_at ? '✅' : '⬜';
                $context .= "- {$done} {$task->title}\n";
            }
        }

        $systemMessage = 'You are a productivity assistant helping a solo developer plan their day. '
            .'Based on the context provided, help prioritize tasks, suggest a realistic daily plan, '
            .'and identify any blockers or concerns. Be concise and actionable. '
            .'Use the SoloBoard MCP tools (add-to-plan, update-task, start-timer) to execute the plan.';

        return [
            Response::text($systemMessage)->asAssistant(),
            Response::text("Please help me plan my day. Here's my current context:\n\n".$context),
        ];
    }
}
