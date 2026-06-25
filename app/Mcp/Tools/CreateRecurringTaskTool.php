<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityPriority;
use App\Enums\RecurrenceFrequency;
use App\Models\Project;
use App\Models\RecurringTask;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateRecurringTaskTool extends Tool
{
    protected string $name = 'create-recurring-task';

    protected string $description = 'Creates a new recurring task that will automatically generate tasks at specified intervals.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'frequency' => 'required|string|in:daily,weekdays,weekly,biweekly,monthly',
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'priority' => 'nullable|string|in:urgent,high,medium,low',
            'project_slug' => 'nullable|string|exists:projects,slug',
            'estimated_minutes' => 'nullable|integer|min:1',
        ]);

        $frequency = RecurrenceFrequency::from($validated['frequency']);
        $priority = ActivityPriority::from($validated['priority'] ?? 'medium');

        $projectId = null;
        if (! empty($validated['project_slug'])) {
            $project = Project::query()->where('slug', $validated['project_slug'])->firstOrFail();
            $projectId = $project->id;
        }

        $nextRun = $this->calculateInitialNextRun(
            $frequency,
            $validated['day_of_week'] ?? null,
            $validated['day_of_month'] ?? null
        );

        $recurring = RecurringTask::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'frequency' => $frequency,
            'day_of_week' => $frequency === RecurrenceFrequency::Weekly ? ($validated['day_of_week'] ?? 1) : null,
            'day_of_month' => $frequency === RecurrenceFrequency::Monthly ? ($validated['day_of_month'] ?? 1) : null,
            'priority' => $priority,
            'project_id' => $projectId,
            'estimated_minutes' => $validated['estimated_minutes'] ?? null,
            'next_run' => $nextRun,
            'is_active' => true,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'message' => "Recurring task '{$recurring->title}' created with {$recurring->frequency->label()} frequency.",
            'recurring_task' => [
                'id' => $recurring->id,
                'title' => $recurring->title,
                'frequency' => $recurring->frequency->value,
                'next_run' => $recurring->next_run->toDateString(),
                'is_active' => $recurring->is_active,
            ],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Title of the recurring task.')->required(),
            'description' => $schema->string()->description('Optional description.'),
            'frequency' => $schema->string()
                ->enum(['daily', 'weekdays', 'weekly', 'biweekly', 'monthly'])
                ->description('How often the task should repeat.')
                ->required(),
            'day_of_week' => $schema->integer()->description('Day of week for weekly tasks (0=Sunday, 6=Saturday).'),
            'day_of_month' => $schema->integer()->description('Day of month for monthly tasks (1-31).'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('Task priority. Default: medium.'),
            'project_slug' => $schema->string()->description('Optional project slug to assign tasks to.'),
            'estimated_minutes' => $schema->integer()->description('Estimated time in minutes.'),
        ];
    }

    private function calculateInitialNextRun(
        RecurrenceFrequency $frequency,
        ?int $dayOfWeek,
        ?int $dayOfMonth
    ): Carbon {
        $today = Carbon::today();

        return match ($frequency) {
            RecurrenceFrequency::Daily => $today->addDay(),
            RecurrenceFrequency::Weekdays => $today->isWeekend() ? $today->nextWeekday() : ($today->copy()->addDay()->isWeekend() ? $today->nextWeekday() : $today->addDay()),
            RecurrenceFrequency::Weekly => $today->addWeek()->setDaysFromStartOfWeek($dayOfWeek ?? 1),
            RecurrenceFrequency::Biweekly => $today->addWeeks(2),
            RecurrenceFrequency::Monthly => $today->addMonth()->setDay(min($dayOfMonth ?? 1, $today->copy()->addMonth()->daysInMonth)),
        };
    }
}
