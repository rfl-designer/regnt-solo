<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityPriority;
use App\Enums\ActivityType;
use App\Enums\ServiceClass;
use App\Enums\StakeholderIssueStatus;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Models\Activity;
use App\Models\StakeholderIssue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class PromoteStakeholderIssueToFeatureTool extends Tool
{
    protected string $name = 'promote-stakeholder-issue';

    protected string $description = 'Converts a stakeholder issue into a feature and links both records. Use after triaging stakeholder feedback.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'issue_id' => 'required|integer|exists:stakeholder_issues,id',
            'title' => 'nullable|string|max:255',
            'spec' => 'nullable|string',
            'priority' => ['nullable', 'string', Rule::enum(ActivityPriority::class)],
            'service_class' => ['nullable', 'string', Rule::enum(ServiceClass::class)],
            'due_date' => 'nullable|date',
        ], [
            'issue_id.required' => 'You must provide the issue_id to promote.',
            'issue_id.exists' => 'Stakeholder issue not found. Use list-stakeholder-issues to find IDs.',
            'priority.Illuminate\Validation\Rules\Enum' => 'Invalid priority. Valid values: urgent, high, medium, low.',
            'service_class.Illuminate\Validation\Rules\Enum' => 'Invalid service_class. Valid values: emergency, fixed_date, standard, intangible.',
        ]);

        $issue = StakeholderIssue::query()
            ->with(['project', 'stakeholder', 'activity'])
            ->findOrFail($validated['issue_id']);

        if ($issue->activity) {
            $issue->update([
                'status' => StakeholderIssueStatus::Feature,
            ]);

            return Response::text(json_encode([
                'issue_id' => $issue->id,
                'feature_id' => $issue->activity->id,
                'feature_title' => $issue->activity->title,
                'project' => $issue->project?->name,
                'created_feature' => false,
                'status' => StakeholderIssueStatus::Feature->value,
            ], JSON_PRETTY_PRINT));
        }

        $title = $validated['title'] ?? $this->generateTitleFromIssue($issue->comment, $issue->id);
        $spec = $validated['spec'] ?? $this->generateFeatureSpec($issue);

        try {
            $feature = Activity::query()->create([
                'type' => ActivityType::Epic,
                'project_id' => $issue->project_id,
                'title' => $title,
                'spec' => $spec,
                'priority' => $validated['priority'] ?? ActivityPriority::Medium,
                'service_class' => $validated['service_class'] ?? ServiceClass::Standard,
                'due_date' => $validated['due_date'] ?? null,
            ]);
        } catch (FixedDateRequiresDueDateException $e) {
            return Response::error($e->getMessage());
        }

        $issue->update([
            'activity_id' => $feature->id,
            'status' => StakeholderIssueStatus::Feature,
            'converted_at' => now(),
        ]);

        return Response::text(json_encode([
            'issue_id' => $issue->id,
            'feature_id' => $feature->id,
            'feature_title' => $feature->title,
            'feature_slug' => $feature->slug,
            'feature_service_class' => $feature->service_class->value,
            'project' => $issue->project?->name,
            'created_feature' => true,
            'status' => StakeholderIssueStatus::Feature->value,
            'converted_at' => $issue->fresh()->converted_at?->toDateTimeString(),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issue_id' => $schema->integer()->description('The ID of the stakeholder issue to convert. Use list-stakeholder-issues to find IDs.')->required(),
            'title' => $schema->string()->description('Optional feature title override. Defaults to a title generated from the issue comment.'),
            'spec' => $schema->string()->description('Optional feature spec in markdown. Defaults to an auto-generated spec based on the issue comment.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('Feature priority. Default: medium.'),
            'service_class' => $schema->string()->enum(['emergency', 'fixed_date', 'standard', 'intangible'])->description('Feature service class. Default: standard. "fixed_date" requires due_date to also be set — the request is refused otherwise.'),
            'due_date' => $schema->string()->description('Optional due date in YYYY-MM-DD format.'),
        ];
    }

    private function generateTitleFromIssue(string $comment, int $issueId): string
    {
        $cleanComment = (string) Str::of($comment)->squish();

        if ($cleanComment === '') {
            return "Feedback de stakeholder #{$issueId}";
        }

        return (string) Str::of($cleanComment)->limit(90, '');
    }

    private function generateFeatureSpec(StakeholderIssue $issue): string
    {
        $projectName = $issue->project?->name ?? 'Projeto';
        $stakeholderName = $issue->stakeholder?->name ?? 'Stakeholder';

        return <<<MARKDOWN
## User Story
Como time de produto, quero tratar o feedback do stakeholder para melhorar o projeto {$projectName}.

## Contexto
Feedback enviado por {$stakeholderName} no portal público de stakeholders.

## Critérios de Aceitação
- [ ] Entender e validar o problema relatado.
- [ ] Definir escopo de implementação.
- [ ] Entregar solução com teste cobrindo o comportamento esperado.

## Feedback Original
{$issue->comment}
MARKDOWN;
    }
}
