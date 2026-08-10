<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Services\EmergencySlotService;
use App\Services\FlowMetricsService;
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

    protected string $description = 'Returns the board pull queue: everything sitting in Pronto (todo), in the order it should be pulled, plus the board context needed to decide. The order has three degraus: the single active Emergência first, then Data fixa items at risk (due within the configured risk window, overdue included) by due date ascending, then everything else in a single FIFO by the last time the item entered Pronto. Each item carries id, title, service_class, project, client, reason (emergency / fixed_date_at_risk / fifo), a human-readable motivo, due_date when it is a Data fixa, and its age on the board in days. The context block reports the Fazendo WIP as count/limit, the active Emergência if any, and the intangible hunger (issue #153): days since the last Intangível entered Feito, the configured threshold, whether it is starving, the anchor of the clock (completion / cut / board / none) and how many Intangíveis are sitting in Pronto — pulling one without concluding it never resets the clock, and a cold start with no conclusion in the history anchors on the current baseline cut and starves regardless of the threshold, including a cut made today. There is no recommendation field: the queue states the order, pulling is the user\'s decision.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, PullQueueService $queue, EmergencySlotService $emergencySlot, FlowMetricsService $flow): Response
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
            // Where the window came from: the measured SLE once the
            // baseline is big enough, the configured N-day guess until
            // then (issue #145). Without this the same number would mean
            // two very different things to a reader.
            'risk_window_source' => $queue->riskWindowFromSle() ? 'sle' : 'config',
            'context' => $this->context($emergencySlot, $flow),
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

        // The due date travels only for a Data fixa. A Padrão can carry a
        // date as a soft target, and emitting it here — next to a
        // countdown — would read as "this one is date-driven too" and
        // invite a client to reorder around a date the queue deliberately
        // ignored.
        $isFixedDate = $activity->service_class === ServiceClass::FixedDate;

        return [
            'position' => $index + 1,
            'id' => $activity->id,
            'title' => $activity->title,
            'service_class' => $activity->service_class->value,
            'project' => $activity->project?->name,
            'client' => $activity->effective_client?->name,
            'reason' => $entry->reason->value,
            'reason_detail' => $entry->positionReason(),
            'due_date' => $isFixedDate ? $activity->due_date?->toDateString() : null,
            'days_to_due_date' => $isFixedDate ? $entry->daysToDueDate : null,
            'age_days' => $entry->ageInDays(),
            'in_pronto_since' => $entry->readySince->toDateTimeString(),
        ];
    }

    /**
     * What the queue can't say on its own: how full Fazendo is, whether an
     * Emergência is already burning, and how hungry the board is for
     * Intangível.
     *
     * The hunger belongs here rather than on any item because it is a
     * property of the *board*, not of a position: it fires even when there
     * is no Intangível in Pronto at all, and in that case `ready_in_pronto`
     * being zero is what tells a client the remedy is to create or promote
     * work of that class instead of pulling one.
     *
     * @return array<string, mixed>
     */
    private function context(EmergencySlotService $emergencySlot, FlowMetricsService $flow): array
    {
        $activeEmergency = $emergencySlot->active();
        $hunger = $flow->intangibleStarvation();

        return [
            'intangible_hunger' => [
                'days' => round($hunger['days'], 1),
                'threshold_days' => $hunger['threshold'],
                'starving' => $hunger['starving'],
                'anchor' => $hunger['anchor'],
                'last_completed_at' => $hunger['last_completed_at']?->toDateTimeString(),
                'ready_in_pronto' => $hunger['ready_count'],
                'label' => $hunger['label'],
            ],
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
