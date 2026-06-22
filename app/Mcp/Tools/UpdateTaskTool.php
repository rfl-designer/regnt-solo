<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateTaskTool extends Tool
{
    protected string $name = 'update-task';

    protected string $description = 'Updates an existing task. When status is changed to "done", the task is automatically marked as done (stops running timers and sets completed_at). Provide only the fields you want to change.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'required|integer|exists:tasks,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'project_slug' => 'nullable|string|exists:projects,slug',
            'status' => ['nullable', 'string', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', 'string', Rule::enum(TaskPriority::class)],
            'due_date' => 'nullable|date',
            'estimated_minutes' => 'nullable|integer|min:1',
            'session_prompt' => 'nullable|string',
            'session_result' => 'nullable|string',
            'feature_id' => 'nullable|integer|exists:features,id',
            'github_issue_number' => 'nullable|integer',
            'github_synced_hash' => 'nullable|string',
        ], [
            'task_id.required' => 'You must provide a task_id. Use list-tasks to find available task IDs.',
            'task_id.exists' => 'Task not found. Use list-tasks to find available task IDs.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'feature_id.exists' => 'Feature not found. Use list-features to find available feature IDs.',
        ]);

        $task = Task::findOrFail($validated['task_id']);

        if (isset($validated['status']) && $validated['status'] === TaskStatus::Done->value && $task->status !== TaskStatus::Done) {
            $task->markAsDone();
            $task->refresh();
        }

        $updates = [];

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
        }

        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }

        if (isset($validated['project_slug'])) {
            $updates['project_id'] = Project::query()->where('slug', $validated['project_slug'])->value('id');
        }

        if (isset($validated['status']) && $validated['status'] !== TaskStatus::Done->value) {
            $updates['status'] = $validated['status'];
        }

        if (isset($validated['priority'])) {
            $updates['priority'] = $validated['priority'];
        }

        if (isset($validated['due_date'])) {
            $updates['due_date'] = $validated['due_date'];
        }

        if (isset($validated['estimated_minutes'])) {
            $updates['estimated_minutes'] = $validated['estimated_minutes'];
        }

        if (array_key_exists('session_prompt', $validated)) {
            $updates['session_prompt'] = $validated['session_prompt'];
        }

        if (array_key_exists('session_result', $validated)) {
            $updates['session_result'] = $validated['session_result'];
        }

        if (array_key_exists('feature_id', $validated)) {
            $updates['feature_id'] = $validated['feature_id'];
        }

        if (isset($validated['github_issue_number'])) {
            $updates['github_issue_number'] = $validated['github_issue_number'];
        }

        if (array_key_exists('github_synced_hash', $validated)) {
            $updates['github_synced_hash'] = $validated['github_synced_hash'];
        }

        if (! empty($updates)) {
            $task->update($updates);
        }

        $task->load('project');

        $data = [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'project' => $task->project?->name,
            'due_date' => $task->due_date?->toDateString(),
            'estimated_minutes' => $task->estimated_minutes,
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'is_overdue' => $task->isOverdue(),
            'is_running' => $task->isRunning(),
            'is_session_task' => $task->isSessionTask(),
            'feature_id' => $task->feature_id,
            'github_issue_number' => $task->github_issue_number,
            'github_synced_hash' => $task->github_synced_hash,
            'updated_at' => $task->updated_at->toDateTimeString(),
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
            'task_id' => $schema->integer()->description('The ID of the task to update.')->required(),
            'title' => $schema->string()->description('New title for the task.'),
            'description' => $schema->string()->description('[DEPRECATED] New description for the task. Use session_prompt instead.'),
            'project_slug' => $schema->string()->description('Slug of the project to assign the task to.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'todo', 'doing', 'done'])->description('New status. Setting to "done" will stop running timers and set completed_at.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('New priority.'),
            'due_date' => $schema->string()->description('New due date in YYYY-MM-DD format.'),
            'estimated_minutes' => $schema->integer()->description('New estimated time in minutes.'),
            'session_prompt' => $schema->string()->description('AI session prompt. When provided, the task becomes a session task (AI-assisted coding session). Use User Story format: "## User Story\nComo [persona], quero [ação] para [benefício].\n\n## Contexto\n[Situação atual]\n\n## Critérios de Aceitação\n- [ ] Critério 1\n- [ ] Critério 2\n\n## Notas Técnicas\n[Arquivos, dependências]"'),
            'session_result' => $schema->string()->description('Result/summary of the AI coding session.'),
            'feature_id' => $schema->integer()->description('ID of the feature this task belongs to (resolved by the sync from the issue parent chain). Use list-features to find IDs.'),
            'github_issue_number' => $schema->integer()->description('GitHub issue number this task mirrors (upsert key).'),
            'github_synced_hash' => $schema->string()->description('Digest of the source GitHub issue, used to gate natural-language rewrites.'),
        ];
    }
}
