<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListTasksTool extends Tool
{
    protected string $name = 'list-tasks';

    protected string $description = 'Lists personal tasks (type=Task) with optional filtering by project id, status, and limit. Personal tasks are local operational/personal to-dos ("email the designer", "chase the brand manual"); they have no GitHub mirror and never appear on the stakeholder board. Returns task id, title, status, service_class, project, parent_id, client (resolved from the project, or the task\'s direct link), waiting_for/waiting_since (set when status is one of the three waiting statuses) and due_date.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'status' => 'nullable|string|in:inbox,backlog,awaiting_approval,todo,doing,waiting,awaiting_validation,done',
            'limit' => 'nullable|integer|min:1|max:100',
        ], [
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'status.in' => 'Invalid status. Valid values: inbox, backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done.',
        ]);

        $query = Activity::query()->tasks()->with(['project.client', 'client']);

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['status'])) {
            $query->byStatus(ActivityStatus::from($validated['status']));
        }

        $limit = $validated['limit'] ?? 20;

        $tasks = $query->latest()->limit($limit)->get();

        $data = $tasks->map(fn (Activity $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'service_class' => $task->service_class->value,
            'project' => $task->project?->name,
            'parent_id' => $task->parent_id,
            'client' => $task->effective_client?->name,
            'waiting_for' => $task->waiting_for,
            'waiting_since' => $task->waiting_since?->toDateTimeString(),
            'due_date' => $task->due_date?->toDateString(),
            'is_overdue' => $task->isOverdue(),
            'is_running' => $task->isRunning(),
        ])->all();

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Filter tasks by project id. Optional. Use list-projects to find ids.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'awaiting_approval', 'todo', 'doing', 'waiting', 'awaiting_validation', 'done'])->description('Filter tasks by status. Optional.'),
            'limit' => $schema->integer()->description('Maximum number of tasks to return. Default: 20, max: 100.'),
        ];
    }
}
