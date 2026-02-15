<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use App\Models\TaskCommit;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class LogCommitsTool extends Tool
{
    protected string $name = 'log-commits';

    protected string $description = 'Logs git commits associated with a task. Ignores duplicate commits by hash. Optionally updates the PR URL.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'required|integer|exists:tasks,id',
            'commits' => 'required|array|min:1',
            'commits.*.hash' => 'required|string|max:40',
            'commits.*.message' => 'required|string',
            'commits.*.files_changed' => 'nullable|integer|min:0',
            'commits.*.insertions' => 'nullable|integer|min:0',
            'commits.*.deletions' => 'nullable|integer|min:0',
            'commits.*.committed_at' => 'nullable|date',
            'pr_url' => 'nullable|string|url|max:500',
        ], [
            'task_id.required' => 'You must provide a task_id.',
            'task_id.exists' => 'Task not found. Use list-tasks to find available task IDs.',
            'commits.required' => 'You must provide at least one commit.',
            'commits.*.hash.required' => 'Each commit must have a hash.',
            'commits.*.message.required' => 'Each commit must have a message.',
        ]);

        $task = Task::findOrFail($validated['task_id']);

        // Update PR URL if provided
        if (! empty($validated['pr_url'])) {
            $task->update(['pr_url' => $validated['pr_url']]);
        }

        // Upsert commits — duplicates by hash are updated (commit may be reassigned between tasks)
        $commitsLogged = 0;
        foreach ($validated['commits'] as $commitData) {
            TaskCommit::updateOrCreate(
                ['hash' => $commitData['hash']],
                [
                    'task_id' => $task->id,
                    'message' => $commitData['message'],
                    'files_changed' => $commitData['files_changed'] ?? 0,
                    'insertions' => $commitData['insertions'] ?? 0,
                    'deletions' => $commitData['deletions'] ?? 0,
                    'committed_at' => $commitData['committed_at'] ?? now(),
                ]
            );
            $commitsLogged++;
        }

        $task->load('commits');

        $data = [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'pr_url' => $task->pr_url,
            'commits_logged' => $commitsLogged,
            'total_commits' => $task->commits->count(),
            'commits' => $task->commits->sortByDesc('committed_at')->values()->map(fn (TaskCommit $c) => [
                'hash' => $c->hash,
                'message' => $c->message,
                'files_changed' => $c->files_changed,
                'insertions' => $c->insertions,
                'deletions' => $c->deletions,
                'committed_at' => $c->committed_at->toDateTimeString(),
            ])->all(),
        ];

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The ID of the task to log commits for.')->required(),
            'commits' => $schema->array()->description('Array of commits to log. Each commit must have a hash and message.')->required(),
            'pr_url' => $schema->string()->description('Optional PR URL to associate with the task.'),
        ];
    }
}
