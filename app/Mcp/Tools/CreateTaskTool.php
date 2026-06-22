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

class CreateTaskTool extends Tool
{
    protected string $name = 'create-task';

    protected string $description = 'Creates a new task. By default, tasks are created with status "inbox" and priority "medium". Optionally assign to a project by slug.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_slug' => 'nullable|string|exists:projects,slug',
            'status' => ['nullable', 'string', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', 'string', Rule::enum(TaskPriority::class)],
            'due_date' => 'nullable|date',
            'estimated_minutes' => 'nullable|integer|min:1',
            'session_prompt' => 'nullable|string',
            'feature_id' => 'nullable|integer|exists:features,id',
            'github_issue_number' => 'nullable|integer',
            'github_synced_hash' => 'nullable|string',
        ], [
            'title.required' => 'You must provide a title for the task.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'feature_id.exists' => 'Feature not found. Use list-features to find available feature IDs.',
            'status.Illuminate\Validation\Rules\Enum' => 'Invalid status. Valid values: inbox, backlog, todo, doing, done.',
            'priority.Illuminate\Validation\Rules\Enum' => 'Invalid priority. Valid values: urgent, high, medium, low.',
        ]);

        $projectId = null;
        if (! empty($validated['project_slug'])) {
            $projectId = Project::query()->where('slug', $validated['project_slug'])->value('id');
        }

        $payload = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'project_id' => $projectId,
            'status' => $validated['status'] ?? TaskStatus::Inbox,
            'priority' => $validated['priority'] ?? TaskPriority::Medium,
            'due_date' => $validated['due_date'] ?? null,
            'estimated_minutes' => $validated['estimated_minutes'] ?? null,
            'session_prompt' => $validated['session_prompt'] ?? null,
        ];

        if (array_key_exists('feature_id', $validated)) {
            $payload['feature_id'] = $validated['feature_id'];
        }

        if (array_key_exists('github_synced_hash', $validated)) {
            $payload['github_synced_hash'] = $validated['github_synced_hash'];
        }

        if (! empty($validated['github_issue_number'])) {
            $task = Task::updateOrCreate(
                ['github_issue_number' => $validated['github_issue_number']],
                $payload,
            );
        } else {
            $task = Task::create($payload);
        }

        if ($task->status === TaskStatus::Done && $task->completed_at === null) {
            $task->markAsDone();
            $task->refresh();
        }

        $task->load('project');

        $data = [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'project' => $task->project?->name,
            'feature_id' => $task->feature_id,
            'due_date' => $task->due_date?->toDateString(),
            'estimated_minutes' => $task->estimated_minutes,
            'is_session_task' => $task->isSessionTask(),
            'github_issue_number' => $task->github_issue_number,
            'github_synced_hash' => $task->github_synced_hash,
            'created_at' => $task->created_at->toDateTimeString(),
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
            'title' => $schema->string()->description('The title of the task.')->required(),
            'description' => $schema->string()->description('[DEPRECATED] Optional description or notes for the task. Use session_prompt instead.'),
            'project_slug' => $schema->string()->description('Slug of the project to assign the task to. Use list-projects to find slugs.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'todo', 'doing', 'done'])->description('Task status. Default: inbox.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('Task priority. Default: medium.'),
            'due_date' => $schema->string()->description('Due date in YYYY-MM-DD format.'),
            'estimated_minutes' => $schema->integer()->description('Estimated time in minutes to complete the task.'),
            'session_prompt' => $schema->string()->description('Optional AI session prompt. When provided, the task becomes a session task (AI-assisted coding session). Use User Story format: "## User Story\nComo [persona], quero [ação] para [benefício].\n\n## Contexto\n[Situação atual]\n\n## Critérios de Aceitação\n- [ ] Critério 1\n- [ ] Critério 2\n\n## Notas Técnicas\n[Arquivos, dependências]"'),
            'feature_id' => $schema->integer()->description('ID of the feature this task belongs to. Resolved by the sync from the issue parent chain. Use list-features to find IDs.'),
            'github_issue_number' => $schema->integer()->description('GitHub issue number this task mirrors. When provided, the task is upserted by this number (no duplicate on repeated syncs).'),
            'github_synced_hash' => $schema->string()->description('Digest of the source GitHub issue (title + body + labels + state), used to gate natural-language rewrites between syncs.'),
        ];
    }
}
