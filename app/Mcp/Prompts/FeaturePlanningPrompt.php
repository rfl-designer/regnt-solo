<?php

namespace App\Mcp\Prompts;

use App\Enums\TaskStatus;
use App\Models\Feature;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class FeaturePlanningPrompt extends Prompt
{
    protected string $description = 'Reads a feature\'s spec and helps plan the implementation. Provides context about the feature, its tasks, and progress to help the AI plan the work.';

    /**
     * Get the prompt's arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'feature_id',
                description: 'The ID of the feature to plan.',
                required: true,
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
        $featureId = $request->integer('feature_id');
        $feature = Feature::query()
            ->with(['project', 'tasks', 'timeEntries'])
            ->findOrFail($featureId);

        $context = "## Feature\n";
        $context .= "- Title: {$feature->title}\n";
        $context .= "- Status: {$feature->status->value}\n";
        $context .= "- Priority: {$feature->priority->value}\n";
        $context .= "- Progress: {$feature->progress}%\n";

        if ($feature->project) {
            $context .= "- Project: {$feature->project->emoji} {$feature->project->name}\n";
            if ($feature->project->description) {
                $context .= "- Project Description: {$feature->project->description}\n";
            }
        }

        if ($feature->due_date) {
            $context .= "- Due Date: {$feature->due_date->toDateString()}\n";
        }

        if ($feature->spec) {
            $context .= "\n## Feature Specification\n{$feature->spec}\n";
        }

        if ($feature->tasks->isNotEmpty()) {
            $context .= "\n## Tasks ({$feature->completedTasksCount()}/{$feature->tasksCount()} completed)\n";

            $tasksByStatus = $feature->tasks->groupBy(fn ($t) => $t->status->value);

            foreach ([TaskStatus::Doing, TaskStatus::Todo, TaskStatus::Backlog, TaskStatus::Done] as $status) {
                $tasks = $tasksByStatus->get($status->value, collect());
                if ($tasks->isEmpty()) {
                    continue;
                }

                $context .= "\n### {$status->label()}\n";
                foreach ($tasks as $task) {
                    $estimated = $task->estimated_minutes ? " ({$task->estimated_minutes}min)" : '';
                    $running = $task->isRunning() ? ' [TIMER RUNNING]' : '';
                    $context .= "- [{$task->id}] {$task->title}{$estimated}{$running}\n";
                }
            }
        }

        $totalTime = round($feature->total_time, 0);
        if ($totalTime > 0) {
            $hours = floor($totalTime / 60);
            $minutes = $totalTime % 60;
            $timeStr = $hours > 0 ? "{$hours}h {$minutes}min" : "{$minutes}min";
            $context .= "\n## Time Spent\n- Total: {$timeStr}\n";
        }

        $systemMessage = 'You are a feature planning assistant helping a solo developer. '
            .'Based on the feature specification and current progress provided, help plan the implementation: '
            .'1) Analyze the current state and remaining work, '
            .'2) Suggest task breakdown if needed (use create-task with feature_id or add-task-to-feature), '
            .'3) Prioritize the tasks and suggest order of execution, '
            .'4) Identify dependencies and potential blockers, '
            .'5) Estimate remaining time. '
            .'Use the SoloBoard MCP tools (start-timer, create-task, add-task-to-feature, update-feature) during implementation.';

        return [
            Response::text($systemMessage)->asAssistant(),
            Response::text("Please help me plan this feature implementation:\n\n".$context),
        ];
    }
}
