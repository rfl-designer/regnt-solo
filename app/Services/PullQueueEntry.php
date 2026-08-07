<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\PullQueueReason;
use App\Models\Activity;
use Carbon\CarbonInterface;

/**
 * One position in the pull queue: the activity, the degrau that put it
 * there, and the two facts that justify the position (issue #144).
 *
 * The entry is what every surface reads — the Kanban's Pronto column and
 * the `get-pull-queue` MCP tool both render *this*, so the motivo the user
 * sees in a tooltip and the `reason` an AI client receives can't drift
 * apart.
 */
final readonly class PullQueueEntry
{
    /**
     * @param  Activity  $activity  The board item queued.
     * @param  PullQueueReason  $reason  The degrau it landed on.
     * @param  CarbonInterface  $readySince  Last entry into Pronto (or the fallback).
     * @param  int|null  $daysToDueDate  Whole days until the Data fixa is due — negative when overdue, null when the item has no due date.
     * @param  int  $readySequence  Id of the status change that put it in Pronto — the FIFO tie-breaker within one second.
     * @param  CarbonInterface|null  $boardSince  First entry into a board column (Inbox doesn't count); null when the item has no such history.
     */
    public function __construct(
        public Activity $activity,
        public PullQueueReason $reason,
        public CarbonInterface $readySince,
        public ?int $daysToDueDate = null,
        public int $readySequence = 0,
        public ?CarbonInterface $boardSince = null,
    ) {}

    /**
     * The one-line PT-BR justification for this position, as shown in the
     * Pronto column's tooltip ("em risco: faltam 3 dias").
     */
    public function positionReason(): string
    {
        return match ($this->reason) {
            PullQueueReason::Emergency => 'Emergência: '.($this->activity->emergency_reason ?? 'fura a fila'),
            PullQueueReason::FixedDateAtRisk => 'em risco: '.$this->dueDatePhrase(),
            PullQueueReason::Fifo => 'ordem de chegada: em Pronto '.$this->readySincePhrase(),
        };
    }

    /**
     * The "idade no quadro": whole days since the item first entered a
     * board column.
     *
     * Creation is deliberately *not* the origin. Inbox is triage, not the
     * board (see {@see ActivityStatus::boardOrder()}), so an
     * idea that sat in the Caixa de Entrada for a month and was triaged
     * into Pronto today is one day old on the board, not thirty. Items
     * with no such history fall back to `created_at`, which for anything
     * created straight onto the board is the same answer.
     */
    public function ageInDays(): int
    {
        $origin = $this->boardSince ?? $this->activity->created_at;

        return (int) $origin->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * How many whole days the item has been sitting in Pronto.
     */
    public function readyDays(): int
    {
        return (int) $this->readySince->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * The sort key for this entry: degrau first, then the axis that degrau
     * orders by, then two tie-breakers.
     *
     * Keeping the key here (rather than in the service's comparator) is
     * what makes "Emergência, then due date ascending, then FIFO" a single
     * readable statement instead of a nested set of ifs.
     *
     * The first tie-breaker is the id of the status change that put the
     * item in Pronto, and it matters: `activity_status_changes.changed_at`
     * is a `dateTime` with second precision, so two moves in the same
     * second are indistinguishable by timestamp alone. Falling straight
     * back to the activity id there would invert the real FIFO whenever
     * the older activity was the one moved second. The activity id remains
     * the last resort, so the order is always total and deterministic.
     *
     * @return array{int, int, int, int}
     */
    public function sortKey(): array
    {
        $axis = match ($this->reason) {
            PullQueueReason::Emergency => $this->activity->emergency_since?->getTimestamp()
                ?? $this->activity->created_at->getTimestamp(),
            PullQueueReason::FixedDateAtRisk => $this->activity->due_date->getTimestamp(),
            PullQueueReason::Fifo => $this->readySince->getTimestamp(),
        };

        return [$this->reason->rank(), $axis, $this->readySequence, $this->activity->id];
    }

    /**
     * "faltam 3 dias" / "vence hoje" / "atrasada há 2 dias".
     */
    private function dueDatePhrase(): string
    {
        $days = $this->daysToDueDate ?? 0;

        if ($days === 0) {
            return 'vence hoje';
        }

        if ($days < 0) {
            $late = abs($days);

            return 'atrasada há '.$late.' '.($late === 1 ? 'dia' : 'dias');
        }

        return 'faltam '.$days.' '.($days === 1 ? 'dia' : 'dias');
    }

    /**
     * "desde hoje" / "há 4 dias".
     */
    private function readySincePhrase(): string
    {
        $days = $this->readyDays();

        if ($days === 0) {
            return 'desde hoje';
        }

        return 'há '.$days.' '.($days === 1 ? 'dia' : 'dias');
    }
}
