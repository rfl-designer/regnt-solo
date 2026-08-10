<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Project;
use App\Services\FlowMetricsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetProjectContextTool extends Tool
{
    protected string $name = 'get-project-context';

    protected string $description = 'Gets complete project context including all documents and active tasks. Use this to understand a project before starting work. Every active epic also carries its apetite guard: appetite.days (the budget chosen in shaping, in calendar days), appetite.consumed_days (calendar days from spec approval to validation, or to now while the bet is live) and appetite.status (ok, warning at 80% of the budget, exceeded at 100%, no_appetite when no budget was chosen, or not_started while the spec has never been approved).';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_slug' => 'required|string|exists:projects,slug',
        ], [
            'project_slug.required' => 'You must provide a project_slug. Use list-projects to find available project slugs.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
        ]);

        $project = Project::query()
            ->where('slug', $validated['project_slug'])
            ->firstOrFail();

        $documents = Document::query()
            ->forProject($project->id)
            ->ordered()
            ->get();

        $activeTasks = Activity::query()
            ->where('project_id', $project->id)
            ->where('status', '!=', ActivityStatus::Done)
            ->with('statusChanges')
            ->orderByServiceClass()
            ->orderBy('due_date')
            ->get();

        // A guarda de apetite (issue #152), lida do mesmo serviço que a UI. O
        // histórico já veio junto, então a lista inteira custa uma consulta.
        $metrics = app(FlowMetricsService::class);

        $allTasks = Activity::query()
            ->where('project_id', $project->id)
            ->get();

        $tasksByStatus = [];
        foreach (ActivityStatus::cases() as $status) {
            $tasksByStatus[$status->value] = $allTasks->where('status', $status)->count();
        }

        $totalTimeMinutes = $allTasks->sum(function (Activity $task) {
            return $task->timeEntries->sum('duration_minutes');
        });

        $overdueCount = $allTasks->filter(fn (Activity $task) => $task->isOverdue())->count();

        $data = [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'description' => $project->description,
                'status' => $project->status->value,
                'priority' => $project->priority->value,
                'created_at' => $project->created_at->toDateTimeString(),
            ],
            'documents' => $documents->map(fn (Document $doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'slug' => $doc->slug,
                'type' => $doc->type->value,
                'type_label' => $doc->type->label(),
                'content' => $doc->content,
                'is_pinned' => $doc->is_pinned,
            ])->all(),
            'active_tasks' => $activeTasks->map(fn (Activity $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'service_class' => $task->service_class->value,
                'session_prompt' => $task->session_prompt,
                'due_date' => $task->due_date?->toDateString(),
                'is_overdue' => $task->isOverdue(),
                'appetite' => $this->appetite($task, $metrics),
            ])->all(),
            'metrics' => [
                'total_tasks' => $allTasks->count(),
                'tasks_by_status' => $tasksByStatus,
                'total_time_minutes' => (int) $totalTimeMinutes,
                'overdue_count' => $overdueCount,
            ],
        ];

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * O apetite de um Épico e o quanto dele já foi (issue #152), ou null para
     * o que não é uma aposta — uma Issue não tem apetite, e publicar um campo
     * vazio nela sugeriria que deveria ter.
     *
     * `not_started` é dito por extenso em vez de virar null: o épico existe e
     * tem (ou não) um orçamento, só ainda não há janela para medir.
     *
     * @return array{days: int|null, consumed_days: float|null, status: string}|null
     */
    private function appetite(Activity $activity, FlowMetricsService $metrics): ?array
    {
        if ($activity->type !== ActivityType::Epic) {
            return null;
        }

        $consumption = $metrics->appetiteConsumption($activity);

        if ($consumption === null) {
            return [
                'days' => ($activity->appetite_days !== null && $activity->appetite_days >= 1) ? (int) $activity->appetite_days : null,
                'consumed_days' => null,
                'status' => 'not_started',
            ];
        }

        return [
            'days' => $consumption['appetite_days'],
            'consumed_days' => round($consumption['consumed_days'], 1),
            'status' => $consumption['level'],
        ];
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_slug' => $schema->string()->description('The slug of the project to get context for.')->required(),
        ];
    }
}
