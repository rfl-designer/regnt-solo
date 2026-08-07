<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\MorningRitual;
use App\Services\FlowMetricsService;
use App\Services\PullQueueService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * O estado do ritual matinal de hoje, como um cliente MCP o lê (issue #147).
 *
 * Read-only by construction, and deliberately so: the ritual is something
 * the user *does*, one click per item, and a tool that could conclude it
 * from outside would record a ritual that never happened. What it offers
 * instead is the same snapshot the wizard walks — what each step would
 * show — so a client can answer "já fiz o ritual hoje?" and "o que estaria
 * me esperando lá?" without pretending to have done it.
 *
 * This replaces `today-plan`, `add-to-plan` and `suggest-tasks`, which are
 * gone with the Daily Planner: there is no list to read, add to or be
 * suggested for. The board is the list.
 */
#[IsReadOnly]
class GetRitualStatusTool extends Tool
{
    protected string $name = 'get-ritual-status';

    protected string $description = 'Returns the state of today\'s morning ritual: whether it has been concluded, at what time, its notes, and a snapshot of what the five steps would show right now — how many concluded items are waiting to be archived, how many items sit in each of the three waiting columns, what is in Fazendo against the WIP limit, how many items are aging past the SLE attention threshold (or that the baseline is too small to say), and how many items are queued in Pronto. Read-only: concluding the ritual is an act the user performs in the app, never over MCP.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, FlowMetricsService $metrics, PullQueueService $queue): Response
    {
        $ritual = MorningRitual::today();

        $payload = [
            'date' => today()->toDateString(),
            'completed' => $ritual?->isCompleted() ?? false,
            'completed_at' => $ritual?->completed_at?->toDateTimeString(),
            'completed_at_label' => $ritual?->completedAtLabel(),
            'notes' => $ritual?->notes,
            'snapshot' => $this->snapshot($metrics, $queue),
        ];

        return Response::text(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * What each of the five steps would be looking at.
     *
     * @return array<string, mixed>
     */
    private function snapshot(FlowMetricsService $metrics, PullQueueService $queue): array
    {
        $waitingCounts = [];

        foreach (ActivityStatus::cases() as $status) {
            if ($status->isWaiting()) {
                $waitingCounts[$status->value] = Activity::query()
                    ->leaf()
                    ->where('status', $status)
                    ->count();
            }
        }

        return [
            'done_to_archive' => Activity::query()
                ->leaf()
                ->where('status', ActivityStatus::Done)
                ->notArchived()
                ->count(),
            'waiting' => $waitingCounts,
            'doing' => [
                'count' => Activity::query()->leaf()->where('status', ActivityStatus::Doing)->count(),
                'limit' => (int) config('soloboard.wip_limit_doing', 2),
            ],
            // Null rather than zero when the baseline can't support the
            // question: "nothing is aging" and "we can't tell yet" are
            // different answers, and collapsing them would let a client
            // report calm that hasn't been measured.
            'aging' => [
                'baseline_usable' => $metrics->isUsable(),
                'sample_size' => $metrics->sampleSize(),
                'sle_days' => $metrics->sleDays(),
                'items_past_attention' => $metrics->isUsable() ? $metrics->agingItems()->count() : null,
                'label' => $metrics->label(),
            ],
            'pull_queue' => [
                'count' => $queue->queue()->count(),
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
        return [];
    }
}
