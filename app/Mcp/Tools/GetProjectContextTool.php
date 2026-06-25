<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetProjectContextTool extends Tool
{
    protected string $name = 'get-project-context';

    protected string $description = 'Gets complete project context including all documents and active tasks. Use this to understand a project before starting work.';

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
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END")
            ->orderBy('due_date')
            ->get();

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
                'priority' => $task->priority->value,
                'session_prompt' => $task->session_prompt,
                'due_date' => $task->due_date?->toDateString(),
                'is_overdue' => $task->isOverdue(),
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
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_slug' => $schema->string()->description('The slug of the project to get context for.')->required(),
        ];
    }
}
