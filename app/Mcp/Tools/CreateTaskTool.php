<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\ServiceClass;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Exceptions\WaitingRequiresWaitingForException;
use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateTaskTool extends Tool
{
    protected string $name = 'create-task';

    protected string $description = 'Creates a personal task (type=Task). Personal tasks are local operational/personal to-dos with no GitHub mirror; they never appear on the stakeholder board. Project and parent are optional: a task may stand alone, or hang off an Epic or Issue via parent_id. A task may also be linked directly to a client via client_id when it has no project — setting both project_id and client_id keeps only the project link. Use list-clients to find available client ids (it also returns clients with no project, which is exactly the case for a direct link). Defaults to status inbox and service class standard. Classifying as fixed_date requires due_date — the request is refused otherwise. Setting status to awaiting_approval, waiting or awaiting_validation (the three waiting statuses) requires waiting_for — client-side waits (awaiting_approval/awaiting_validation) auto-fill it from the effective client when omitted, waiting has no default and is refused without one. When status is "done", the task is marked done (completed_at set, running timers stopped).';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'nullable|integer|exists:projects,id',
            'parent_id' => 'nullable|integer|exists:activities,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'status' => ['nullable', 'string', Rule::enum(ActivityStatus::class)],
            'service_class' => ['nullable', 'string', Rule::enum(ServiceClass::class)],
            'waiting_for' => 'nullable|string|max:255',
            'due_date' => 'nullable|date',
            'estimated_minutes' => 'nullable|integer|min:1',
        ], [
            'title.required' => 'You must provide a title for the task.',
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'parent_id.exists' => 'Parent not found. Use list-epics or list-issues to find available ids.',
            'client_id.exists' => 'Client not found. Use list-clients to find available client ids.',
            'status.Illuminate\Validation\Rules\Enum' => 'Invalid status. Valid values: inbox, backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done.',
            'service_class.Illuminate\Validation\Rules\Enum' => 'Invalid service_class. Valid values: emergency, fixed_date, standard, intangible.',
        ]);

        try {
            $task = Activity::create([
                'type' => ActivityType::Task,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'project_id' => $validated['project_id'] ?? null,
                'parent_id' => $validated['parent_id'] ?? null,
                'client_id' => $validated['client_id'] ?? null,
                'status' => $validated['status'] ?? ActivityStatus::Inbox,
                'service_class' => $validated['service_class'] ?? ServiceClass::Standard,
                'waiting_for' => $validated['waiting_for'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'estimated_minutes' => $validated['estimated_minutes'] ?? null,
            ]);
        } catch (FixedDateRequiresDueDateException|WaitingRequiresWaitingForException $e) {
            return Response::error($e->getMessage());
        }

        if ($task->status === ActivityStatus::Done && $task->completed_at === null) {
            $task->markAsDone();
            $task->refresh();
        }

        $task->load('project', 'client');

        $data = [
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
            'estimated_minutes' => $task->estimated_minutes,
            'created_at' => $task->created_at->toDateTimeString(),
        ];

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
            'title' => $schema->string()->description('The title of the task.')->required(),
            'description' => $schema->string()->description('Task description in markdown format.'),
            'project_id' => $schema->integer()->description('Id of the project to assign the task to. Optional — tasks may stand alone. Use list-projects to find ids.'),
            'parent_id' => $schema->integer()->description('Id of the parent activity (an Epic or Issue) to hang the task off. Optional.'),
            'client_id' => $schema->integer()->description('Id of the client to link the task to directly. Optional, and only meaningful when project_id is not set — setting both keeps only the project link. Use list-clients to find available ids.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'awaiting_approval', 'todo', 'doing', 'waiting', 'awaiting_validation', 'done'])->description('Task status. Default: inbox. The 7-column board order (excluding inbox) is: backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done. Setting to "done" stops running timers and sets completed_at.'),
            'service_class' => $schema->string()->enum(['emergency', 'fixed_date', 'standard', 'intangible'])->description('Task service class (replaces priority). Default: standard. "fixed_date" requires due_date to also be set — the request is refused otherwise.'),
            'waiting_for' => $schema->string()->description('Who the task is waiting on ("esperando quem"). Required when status is awaiting_approval, waiting or awaiting_validation — client-side waits (awaiting_approval/awaiting_validation) auto-fill this from the effective client when omitted; waiting (internal) has no default and the request is refused without an explicit value.'),
            'due_date' => $schema->string()->description('Due date in YYYY-MM-DD format.'),
            'estimated_minutes' => $schema->integer()->description('Estimated time in minutes to complete the task.'),
        ];
    }
}
