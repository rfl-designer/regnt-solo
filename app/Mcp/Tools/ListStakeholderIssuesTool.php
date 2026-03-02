<?php

namespace App\Mcp\Tools;

use App\Enums\StakeholderIssueStatus;
use App\Models\Project;
use App\Models\StakeholderIssue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListStakeholderIssuesTool extends Tool
{
    protected string $name = 'list-stakeholder-issues';

    protected string $description = 'Lists stakeholder issues created in the public stakeholder portal. Use this to review feedback and find issues ready to become features.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_slug' => 'nullable|string|exists:projects,slug',
            'status' => ['nullable', 'string', Rule::enum(StakeholderIssueStatus::class)],
            'limit' => 'nullable|integer|min:1|max:100',
        ], [
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'status.Illuminate\Validation\Rules\Enum' => 'Invalid issue status. Valid values: unread, to_feature, feature, archived.',
        ]);

        $query = StakeholderIssue::query()
            ->with(['project', 'stakeholder', 'feature'])
            ->latest('created_at');

        if (! empty($validated['project_slug'])) {
            $projectId = Project::query()
                ->where('slug', $validated['project_slug'])
                ->value('id');

            $query->where('project_id', $projectId);
        }

        if (! empty($validated['status'])) {
            $query->where('status', StakeholderIssueStatus::from($validated['status'])->value);
        }

        $limit = $validated['limit'] ?? 20;

        $issues = $query->limit($limit)->get();

        $data = $issues->map(fn (StakeholderIssue $issue) => [
            'id' => $issue->id,
            'comment' => $issue->comment,
            'status' => $issue->status->value,
            'status_label' => $issue->status->label(),
            'project' => [
                'id' => $issue->project?->id,
                'name' => $issue->project?->name,
                'slug' => $issue->project?->slug,
            ],
            'stakeholder' => [
                'id' => $issue->stakeholder?->id,
                'name' => $issue->stakeholder?->name,
                'email' => $issue->stakeholder?->email,
            ],
            'feature' => $issue->feature ? [
                'id' => $issue->feature->id,
                'title' => $issue->feature->title,
                'slug' => $issue->feature->slug,
            ] : null,
            'converted_at' => $issue->converted_at?->toDateTimeString(),
            'created_at' => $issue->created_at->toDateTimeString(),
            'updated_at' => $issue->updated_at->toDateTimeString(),
        ])->all();

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
            'project_slug' => $schema->string()->description('Filter issues by project slug. Use list-projects to find slugs.'),
            'status' => $schema->string()->enum(['unread', 'to_feature', 'feature', 'archived'])->description('Filter issues by status.'),
            'limit' => $schema->integer()->description('Maximum number of issues to return. Default: 20, max: 100.'),
        ];
    }
}
