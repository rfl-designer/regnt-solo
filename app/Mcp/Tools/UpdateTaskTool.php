<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Exceptions\WaitingRequiresWaitingForException;
use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateTaskTool extends Tool
{
    protected string $name = 'update-task';

    protected string $description = 'Updates an existing personal task (type=Task). Accepts title, description, project_id, parent_id, client_id, status, service_class, waiting_for, due_date and estimated_minutes. When status is changed to "done", the task is marked done (stops running timers and sets completed_at). Classifying as fixed_date requires due_date — the request is refused otherwise. Setting status to awaiting_approval, waiting or awaiting_validation requires waiting_for — client-side waits (awaiting_approval/awaiting_validation) auto-fill it from the effective client when omitted, waiting (internal) has no default and is refused without one, exactly like the UI. Provide only the fields you want to change.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'required|integer|exists:activities,id',
            'title' => 'nullable|string|max:255',
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
            'task_id.required' => 'You must provide a task_id. Use list-tasks to find available task ids.',
            'task_id.exists' => 'Task not found. Use list-tasks to find available task ids.',
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'parent_id.exists' => 'Parent not found. Use list-epics or list-issues to find available ids.',
            'client_id.exists' => 'Client not found. Use list-clients to find available client ids.',
        ]);

        $task = Activity::query()->tasks()->findOrFail($validated['task_id']);

        $updates = [];

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
        }

        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }

        if (array_key_exists('project_id', $validated)) {
            $updates['project_id'] = $validated['project_id'];
        }

        if (array_key_exists('parent_id', $validated)) {
            $updates['parent_id'] = $validated['parent_id'];
        }

        if (array_key_exists('client_id', $validated)) {
            $updates['client_id'] = $validated['client_id'];
        }

        if (isset($validated['status']) && $validated['status'] !== ActivityStatus::Done->value) {
            $updates['status'] = $validated['status'];
        }

        if (isset($validated['service_class'])) {
            $updates['service_class'] = $validated['service_class'];
        }

        if (array_key_exists('waiting_for', $validated)) {
            $updates['waiting_for'] = $validated['waiting_for'];
        }

        if (isset($validated['due_date'])) {
            $updates['due_date'] = $validated['due_date'];
        }

        if (isset($validated['estimated_minutes'])) {
            $updates['estimated_minutes'] = $validated['estimated_minutes'];
        }

        // Applied inside one transaction: a request combining status=done
        // with an invalid service_class/due_date/waiting_for pairing must
        // not leave markAsDone's side effects (status, completed_at,
        // stopped timers) committed when the rest of the update is refused.
        try {
            DB::transaction(function () use ($task, $validated, $updates): void {
                if (isset($validated['status']) && $validated['status'] === ActivityStatus::Done->value && $task->status !== ActivityStatus::Done) {
                    $task->markAsDone();
                    $task->refresh();
                }

                if (! empty($updates)) {
                    $task->update($updates);
                }
            });
        } catch (FixedDateRequiresDueDateException|WaitingRequiresWaitingForException $e) {
            return Response::error($e->getMessage());
        }

        $task->refresh()->load('project', 'client');

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
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'is_overdue' => $task->isOverdue(),
            'is_running' => $task->isRunning(),
            'updated_at' => $task->updated_at->toDateTimeString(),
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
            'task_id' => $schema->integer()->description('The id of the task to update.')->required(),
            'title' => $schema->string()->description('New title for the task.'),
            'description' => $schema->string()->description('New description in markdown format.'),
            'project_id' => $schema->integer()->description('Id of the project to assign the task to.'),
            'parent_id' => $schema->integer()->description('Id of the parent activity (an Epic or Issue) to hang the task off.'),
            'client_id' => $schema->integer()->description('Id of the client to link the task to directly. Only meaningful when project_id is not set.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'awaiting_approval', 'todo', 'doing', 'waiting', 'awaiting_validation', 'done'])->description('New status. The 7-column board order (excluding inbox) is: backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done. Setting to "done" will stop running timers and set completed_at.'),
            'service_class' => $schema->string()->enum(['emergency', 'fixed_date', 'standard', 'intangible'])->description('New service class (replaces priority). "fixed_date" requires due_date to also be set — the request is refused otherwise.'),
            'waiting_for' => $schema->string()->description('Who the task is waiting on ("esperando quem"). Required when status is awaiting_approval, waiting or awaiting_validation — client-side waits auto-fill this from the effective client when omitted; waiting (internal) has no default and is refused without an explicit value.'),
            'due_date' => $schema->string()->description('New due date in YYYY-MM-DD format.'),
            'estimated_minutes' => $schema->integer()->description('New estimated time in minutes.'),
        ];
    }
}
