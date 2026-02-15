<?php

namespace App\Mcp\Prompts;

use App\Models\Task;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class SessionPlanningPrompt extends Prompt
{
    protected string $description = 'Reads a task\'s session prompt and helps plan the coding session. Provides context about the task, project, and related work to help the AI plan the implementation.';

    /**
     * Get the prompt's arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'task_id',
                description: 'The ID of the session task to plan.',
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
        $taskId = $request->integer('task_id');
        $task = Task::query()
            ->with(['project', 'commits', 'timeEntries', 'statusChanges'])
            ->findOrFail($taskId);

        $context = "## Session Task\n";
        $context .= "- Title: {$task->title}\n";
        $context .= "- Status: {$task->status->value}\n";
        $context .= "- Priority: {$task->priority->value}\n";

        if ($task->project) {
            $context .= "- Project: {$task->project->emoji} {$task->project->name}\n";
            $context .= "- Project Description: {$task->project->description}\n";
        }

        if ($task->description) {
            $context .= "\n## Task Description\n{$task->description}\n";
        }

        if ($task->session_prompt) {
            $context .= "\n## Session Prompt\n{$task->session_prompt}\n";
        }

        if ($task->commits->isNotEmpty()) {
            $context .= "\n## Existing Commits ({$task->commits->count()})\n";
            foreach ($task->commits->sortByDesc('committed_at')->take(10) as $commit) {
                $context .= "- {$commit->hash}: {$commit->message}\n";
            }
        }

        if ($task->pr_url) {
            $context .= "\n## Pull Request\n- URL: {$task->pr_url}\n";
        }

        if ($task->project) {
            $relatedTasks = Task::query()
                ->where('project_id', $task->project_id)
                ->where('id', '!=', $task->id)
                ->whereIn('status', ['doing', 'todo'])
                ->limit(5)
                ->get();

            if ($relatedTasks->isNotEmpty()) {
                $context .= "\n## Related Tasks (same project)\n";
                foreach ($relatedTasks as $related) {
                    $context .= "- [{$related->status->value}] {$related->title}\n";
                }
            }
        }

        $systemMessage = 'You are a coding session planner helping a solo developer. '
            .'Based on the session prompt and context provided, help plan the implementation: '
            .'1) Break down the work into steps, '
            .'2) Identify potential challenges, '
            .'3) Suggest the approach and architecture, '
            .'4) Estimate time needed. '
            .'Use the SoloBoard MCP tools (start-timer, log-commits, update-task) during the session.';

        return [
            Response::text($systemMessage)->asAssistant(),
            Response::text("Please help me plan this coding session:\n\n".$context),
        ];
    }
}
