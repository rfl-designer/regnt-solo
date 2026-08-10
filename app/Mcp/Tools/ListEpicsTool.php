<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use App\Services\FlowMetricsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListEpicsTool extends Tool
{
    protected string $name = 'list-epics';

    protected string $description = 'Lists roadmap epics (type=Epic) with optional filtering by project id and status. Returns epic id, title, slug, status (manual), priority, progress percentage, issues count, total time, GitHub mirror fields, and the apetite guard: appetite_days (the budget chosen in shaping, in calendar days), appetite_consumed_days (calendar days from spec approval to validation, or to now while the bet is live) and appetite_status (ok, warning at 80% of the budget, exceeded at 100%, or no_appetite when no budget was chosen). appetite_status is null while the spec has never been approved — there is no bet running to measure.';

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

        $query = Activity::query()->epics()->with(['project', 'children', 'timeEntries', 'statusChanges']);

        if (! empty($validated['project_id'])) {
            $query->forProject($validated['project_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $limit = $validated['limit'] ?? 20;

        $epics = $query->ordered()->limit($limit)->get();

        // A guarda de apetite (issue #152) sai do mesmo serviço que a barra do
        // modal e a lista da página Fluxo — o agente lê a mesma conta que o
        // usuário vê, e o histórico já vem carregado, então não há uma consulta
        // por épico.
        $metrics = app(FlowMetricsService::class);

        $data = $epics->map(fn (Activity $epic) => [
            'id' => $epic->id,
            'title' => $epic->title,
            'slug' => $epic->slug,
            'status' => $epic->status->value,
            'priority' => $epic->priority->value,
            'progress' => $epic->progress,
            'issues_count' => $epic->tasksCount(),
            'completed_issues' => $epic->completedTasksCount(),
            'total_time_minutes' => round($epic->total_time, 0),
            'project' => $epic->project?->name,
            'waiting_for' => $epic->waiting_for,
            'waiting_since' => $epic->waiting_since?->toDateTimeString(),
            'due_date' => $epic->due_date?->toDateString(),
            'is_running' => $epic->isRunning(),
            'github_issue_number' => $epic->github_issue_number,
            'github_synced_hash' => $epic->github_synced_hash,
            ...$this->appetiteFields($metrics->appetiteConsumption($epic), $epic),
        ])->all();

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * O apetite e o quanto dele já foi, como o MCP publica (issue #152).
     *
     * `appetite_status` é null enquanto não há aposta correndo: sem aprovação
     * não existe janela, e um "ok" ali diria que a aposta está dentro do
     * orçamento quando ela nem começou.
     *
     * @param  array{appetite_days: int|null, consumed_days: float, level: string}|null  $consumption
     * @return array{appetite_days: int|null, appetite_consumed_days: float|null, appetite_status: string|null}
     */
    private function appetiteFields(?array $consumption, Activity $epic): array
    {
        if ($consumption === null) {
            return [
                'appetite_days' => ($epic->appetite_days !== null && $epic->appetite_days >= 1) ? (int) $epic->appetite_days : null,
                'appetite_consumed_days' => null,
                'appetite_status' => null,
            ];
        }

        return [
            'appetite_days' => $consumption['appetite_days'],
            'appetite_consumed_days' => round($consumption['consumed_days'], 1),
            'appetite_status' => $consumption['level'],
        ];
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Filter epics by project id. Optional. Use list-projects to find ids.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'awaiting_approval', 'todo', 'doing', 'waiting', 'awaiting_validation', 'done'])->description('Filter epics by status. Optional.'),
            'limit' => $schema->integer()->description('Maximum number of epics to return. Default: 20, max: 100.'),
        ];
    }
}
