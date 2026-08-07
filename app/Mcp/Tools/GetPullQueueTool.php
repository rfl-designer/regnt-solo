<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Services\EmergencySlotService;
use App\Services\PullQueueEntry;
use App\Services\PullQueueService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The pull queue, as an AI client reads it (issue #144).
 *
 * The tool ranks nothing: it renders {@see PullQueueService} — the same
 * service the Kanban's Pronto column renders — so a client and the person
 * looking at the board are always answering "o que puxo agora?" from one
 * ordering.
 *
 * Deliberately no recommendation field. The queue states the order and the
 * motivo behind each position, plus the context needed to decide (how full
 * Fazendo is, whether an Emergência is burning); choosing to pull is the
 * user's act, and a "recommended: true" would quietly turn a method into
 * an instruction.
 */
#[IsReadOnly]
class GetPullQueueTool extends Tool
{
    protected string $name = 'get-pull-queue';

    protected string $description = 'Returns the board pull queue: everything sitting in Pronto (todo), in the order it should be pulled, plus the board context needed to decide. The order has three degraus: the single active Emergência first, then Data fixa items at risk (due within the configured risk window, overdue included) by due date ascending, then everything else in a single FIFO by the last time the item entered Pronto. Each item carries id, title, service_class, project, client, reason (emergency / fixed_date_at_risk / fifo), a human-readable motivo, due_date when it is a Data fixa, and its age on the board in days. The context block reports the Fazendo WIP as count/limit and the active Emergência, if any. There is no recommendation field: the queue states the order, pulling is the user\'s decision.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, PullQueueService $queue, EmergencySlotService $emergencySlot): Response
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'limit' => 'nullable|integer|min:1|max:100',
        ], [
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
        ]);

        $entries = $queue->queue(function ($query) use ($validated): void {
            if (! empty($validated['project_id'])) {
                $query->where('project_id', $validated['project_id']);
            }
        });

        $limited = isset($validated['limit'])
            ? $entries->take($validated['limit'])
            : $entries;

        $payload = [
            'queue' => $limited
                ->values()
                ->map(fn (PullQueueEntry $entry, int $index): array => $this->itemFor($entry, $index))
                ->all(),
            'total_in_pronto' => $entries->count(),
            'risk_window_days' => $queue->riskWindowDays(),
            'context' => $this->context($emergencySlot),
        ];

        return Response::text(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * One position in the queue.
     *
     * @return array<string, mixed>
     */
    private function itemFor(PullQueueEntry $entry, int $index): array
    {
        $activity = $entry->activity;

        return [
            'position' => $index + 1,
            'id' => $activity->id,
            'title' => $activity->title,
            'service_class' => $activity->service_class->value,
            'project' => $activity->project?->name,
            'client' => $activity->effective_client?->name,
            'reason' => $entry->reason->value,
            'reason_detail' => $entry->positionReason(),
            'due_date' => $activity->due_date?->toDateString(),
            'days_to_due_date' => $entry->daysToDueDate,
            'age_days' => $entry->ageInDays(),
            'in_pronto_since' => $entry->readySince->toDateTimeString(),
        ];
    }

    /**
     * What the queue can't say on its own: how full Fazendo is, and
     * whether an Emergência is already burning.
     *
     * @return array<string, mixed>
     */
    private function context(EmergencySlotService $emergencySlot): array
    {
        $activeEmergency = $emergencySlot->active();

        return [
            'doing_wip' => [
                'count' => Activity::query()->leaf()->where('status', ActivityStatus::Doing)->count(),
                'limit' => (int) config('soloboard.wip_limit_doing', 2),
            ],
            'active_emergency' => $activeEmergency === null ? null : [
                'id' => $activeEmergency->id,
                'title' => $activeEmergency->title,
                'status' => $activeEmergency->status?->value,
                'reason' => $activeEmergency->emergency_reason,
                'days_active' => $activeEmergency->emergencyDays(),
            ],
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
            'project_id' => $schema->integer()->description('Restrict the queue to one project. Optional. Use list-projects to find ids.'),
            'limit' => $schema->integer()->description('Return only the first N positions. Optional; the total in Pronto is reported regardless.'),
        ];
    }
}
