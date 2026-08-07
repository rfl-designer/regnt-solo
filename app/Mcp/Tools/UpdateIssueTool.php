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
class UpdateIssueTool extends Tool
{
    protected string $name = 'update-issue';

    protected string $description = 'Updates an existing roadmap issue (type=Issue). Accepts status, project_id and parent_id (the parent_id link is the second pass of the sync, where the tree is wired up). When status is changed to "done", the issue is marked done (stops running timers and sets completed_at). Classifying as fixed_date requires due_date — the request is refused otherwise. Setting status to awaiting_approval, waiting or awaiting_validation requires waiting_for — client-side waits auto-fill it from the effective client when omitted, waiting (internal) has no default and is refused without one. Provide only the fields you want to change.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'issue_id' => 'required|integer|exists:activities,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'nullable|integer|exists:projects,id',
            'parent_id' => 'nullable|integer|exists:activities,id',
            'status' => ['nullable', 'string', Rule::enum(ActivityStatus::class)],
            'service_class' => ['nullable', 'string', Rule::enum(ServiceClass::class)],
            'waiting_for' => 'nullable|string|max:255',
            'due_date' => 'nullable|date',
            'estimated_minutes' => 'nullable|integer|min:1',
            'github_issue_number' => 'nullable|integer',
            'github_synced_hash' => 'nullable|string',
        ], [
            'issue_id.required' => 'You must provide an issue_id. Use list-issues to find available issue ids.',
            'issue_id.exists' => 'Issue not found. Use list-issues to find available issue ids.',
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'parent_id.exists' => 'Parent not found. Use list-epics or list-issues to find available ids.',
        ]);

        $issue = Activity::query()->issues()->findOrFail($validated['issue_id']);

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

        if (isset($validated['github_issue_number'])) {
            $updates['github_issue_number'] = $validated['github_issue_number'];
        }

        if (array_key_exists('github_synced_hash', $validated)) {
            $updates['github_synced_hash'] = $validated['github_synced_hash'];
        }

        // Applied inside one transaction: a request combining status=done
        // with an invalid service_class/due_date pairing must not leave
        // markAsDone's side effects (status, completed_at, stopped timers)
        // committed when the rest of the update is refused.
        try {
            DB::transaction(function () use ($issue, $validated, $updates): void {
                if (isset($validated['status']) && $validated['status'] === ActivityStatus::Done->value && $issue->status !== ActivityStatus::Done) {
                    $issue->markAsDone();
                    $issue->refresh();
                }

                if (! empty($updates)) {
                    $issue->update($updates);
                }
            });
        } catch (FixedDateRequiresDueDateException|WaitingRequiresWaitingForException $e) {
            return Response::error($e->getMessage());
        }

        $issue->refresh()->load('project');

        $data = [
            'id' => $issue->id,
            'title' => $issue->title,
            'status' => $issue->status->value,
            'service_class' => $issue->service_class->value,
            'project' => $issue->project?->name,
            'parent_id' => $issue->parent_id,
            'waiting_for' => $issue->waiting_for,
            'waiting_since' => $issue->waiting_since?->toDateTimeString(),
            'due_date' => $issue->due_date?->toDateString(),
            'estimated_minutes' => $issue->estimated_minutes,
            'completed_at' => $issue->completed_at?->toDateTimeString(),
            'is_overdue' => $issue->isOverdue(),
            'is_running' => $issue->isRunning(),
            'github_issue_number' => $issue->github_issue_number,
            'github_synced_hash' => $issue->github_synced_hash,
            'updated_at' => $issue->updated_at->toDateTimeString(),
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
            'issue_id' => $schema->integer()->description('The id of the issue to update.')->required(),
            'title' => $schema->string()->description('New title for the issue.'),
            'description' => $schema->string()->description('New description in markdown format.'),
            'project_id' => $schema->integer()->description('Id of the project to assign the issue to.'),
            'parent_id' => $schema->integer()->description('Id of the parent activity (an Epic -> Fatia, or another Issue -> Follow-up). Set null for a loose issue (Avulsa).'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'awaiting_approval', 'todo', 'doing', 'waiting', 'awaiting_validation', 'done'])->description('New status. The 7-column board order (excluding inbox) is: backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done. Setting to "done" will stop running timers and set completed_at.'),
            'service_class' => $schema->string()->enum(['emergency', 'fixed_date', 'standard', 'intangible'])->description('New service class (replaces priority). "fixed_date" requires due_date to also be set — the request is refused otherwise.'),
            'waiting_for' => $schema->string()->description('Who the issue is waiting on ("esperando quem"). Required when status is awaiting_approval, waiting or awaiting_validation — client-side waits auto-fill this from the effective client when omitted; waiting (internal) has no default and is refused without an explicit value.'),
            'due_date' => $schema->string()->description('New due date in YYYY-MM-DD format.'),
            'estimated_minutes' => $schema->integer()->description('New estimated time in minutes.'),
            'github_issue_number' => $schema->integer()->description('GitHub issue number this issue mirrors (upsert key).'),
            'github_synced_hash' => $schema->string()->description('Digest of the source GitHub issue, used to gate natural-language rewrites.'),
        ];
    }
}
