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

class CreateIssueTool extends Tool
{
    protected string $name = 'create-issue';

    protected string $description = 'Creates a roadmap issue (type=Issue). Issues mirror GitHub issues without the type:prd label; their client-facing label (Fatia/Follow-up/Avulsa) is derived from parent_id. Accepts status, project_id and parent_id, and upserts by github_issue_number. Classifying as fixed_date requires due_date — the request is refused otherwise. When status is "done", the issue is marked done (completed_at set, running timers stopped).';

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
            'status' => ['nullable', 'string', Rule::enum(ActivityStatus::class)],
            'service_class' => ['nullable', 'string', Rule::enum(ServiceClass::class)],
            'waiting_for' => 'nullable|string|max:255',
            'due_date' => 'nullable|date',
            'estimated_minutes' => 'nullable|integer|min:1',
            'github_issue_number' => 'nullable|integer',
            'github_synced_hash' => 'nullable|string',
        ], [
            'title.required' => 'You must provide a title for the issue.',
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'parent_id.exists' => 'Parent not found. Use list-epics or list-issues to find available ids.',
            'status.Illuminate\Validation\Rules\Enum' => 'Invalid status. Valid values: inbox, backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done.',
            'service_class.Illuminate\Validation\Rules\Enum' => 'Invalid service_class. Valid values: emergency, fixed_date, standard, intangible.',
        ]);

        $payload = [
            'type' => ActivityType::Issue,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'status' => $validated['status'] ?? ActivityStatus::Inbox,
            'service_class' => $validated['service_class'] ?? ServiceClass::Standard,
            'waiting_for' => $validated['waiting_for'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'estimated_minutes' => $validated['estimated_minutes'] ?? null,
        ];

        if (array_key_exists('parent_id', $validated)) {
            $payload['parent_id'] = $validated['parent_id'];
        }

        if (array_key_exists('github_synced_hash', $validated)) {
            $payload['github_synced_hash'] = $validated['github_synced_hash'];
        }

        try {
            if (! empty($validated['github_issue_number'])) {
                $issue = Activity::updateOrCreate(
                    ['project_id' => $payload['project_id'], 'github_issue_number' => $validated['github_issue_number']],
                    $payload,
                );
            } else {
                $issue = Activity::create($payload);
            }
        } catch (FixedDateRequiresDueDateException|WaitingRequiresWaitingForException $e) {
            return Response::error($e->getMessage());
        }

        if ($issue->status === ActivityStatus::Done && $issue->completed_at === null) {
            $issue->markAsDone();
            $issue->refresh();
        }

        $issue->load('project');

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
            'github_issue_number' => $issue->github_issue_number,
            'github_synced_hash' => $issue->github_synced_hash,
            'created_at' => $issue->created_at->toDateTimeString(),
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
            'title' => $schema->string()->description('The title of the issue.')->required(),
            'description' => $schema->string()->description('Issue description in markdown format (rewritten in plain product language for the stakeholder board).'),
            'project_id' => $schema->integer()->description('Id of the project to assign the issue to. Use list-projects to find ids.'),
            'parent_id' => $schema->integer()->description('Id of the parent activity (an Epic -> Fatia, or another Issue -> Follow-up). Omit for a loose issue (Avulsa). Resolved by the sync from the issue parent chain.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'awaiting_approval', 'todo', 'doing', 'waiting', 'awaiting_validation', 'done'])->description('Issue status. Default: inbox. The 7-column board order (excluding inbox) is: backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done. Setting to "done" stops running timers and sets completed_at.'),
            'service_class' => $schema->string()->enum(['emergency', 'fixed_date', 'standard', 'intangible'])->description('Issue service class (replaces priority). Default: standard. "fixed_date" requires due_date to also be set — the request is refused otherwise.'),
            'waiting_for' => $schema->string()->description('Who the issue is waiting on ("esperando quem"). Required when status is awaiting_approval, waiting or awaiting_validation — client-side waits auto-fill this from the effective client when omitted; waiting (internal) has no default and is refused without an explicit value.'),
            'due_date' => $schema->string()->description('Due date in YYYY-MM-DD format.'),
            'estimated_minutes' => $schema->integer()->description('Estimated time in minutes to complete the issue.'),
            'github_issue_number' => $schema->integer()->description('GitHub issue number this issue mirrors. When provided, the issue is upserted by this number (no duplicate on repeated syncs).'),
            'github_synced_hash' => $schema->string()->description('Digest of the source GitHub issue (title + body + labels + state), used to gate natural-language rewrites between syncs.'),
        ];
    }
}
