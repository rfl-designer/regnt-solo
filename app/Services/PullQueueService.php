<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\PullQueueReason;
use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Observers\ActivityObserver;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The board's pull queue: what to take next out of Pronto, and why
 * (issue #144).
 *
 * There is exactly one answer to that question, and it lives here. The
 * Kanban's Pronto column and the `get-pull-queue` MCP tool are both thin
 * readers of {@see queue()} — neither sorts anything itself — so the order
 * a human sees dragging a card and the order an AI client reads over MCP
 * are the same order by construction, not by two implementations agreeing.
 *
 * The three degraus, in order:
 *
 * 1. **Emergência.** At most one can be active (guarded by
 *    {@see ActivityObserver}), and it always leads.
 * 2. **Data fixa em risco**, by due date ascending — "em risco" meaning
 *    the due date is within {@see riskWindowDays()} (an overdue one is at
 *    risk too). A Data fixa still comfortably far out is *not*
 *    promoted; it waits its turn in FIFO like everything else, which is
 *    the whole point of classifying by date rather than by shouting.
 * 3. **Everything else** (Padrão, Intangível, Data fixa fora de risco) in
 *    a single FIFO by the last entry into Pronto.
 *
 * FIFO is measured from the *last* entry into Pronto, read from
 * {@see ActivityStatusChange}: an item pulled into Fazendo and pushed back
 * has rejoined the queue, and rejoins it at the end. Items with no such
 * history — created straight into Pronto before the observer existed, or
 * by a bulk write that skipped model events — fall back to `created_at`,
 * which is the closest honest answer available.
 */
class PullQueueService
{
    public function __construct(private readonly FlowMetricsService $metrics) {}

    /**
     * The risk window, in days: how long before its due date a Data fixa
     * has to be pulled to still be delivered on time.
     *
     * There are two answers, and the queue uses the better one available.
     * Once the board has enough measured history the window *is* the SLE
     * — "we finish 85% of items in Y days, so a Data fixa less than Y days
     * out is already at risk" is a measured statement, and it replaces the
     * configured N-day guess automatically, with no switch to flip
     * (issue #145). Below the minimum sample the SLE is not a promise, so
     * `soloboard.fixed_date_risk_days` stays in charge — the honest
     * approximation beats an under-powered percentile.
     *
     * Both are read on every call so the queue can never drift from the
     * configured method or from the current baseline.
     */
    public function riskWindowDays(): int
    {
        return $this->metrics->sleDays() ?? (int) config('soloboard.fixed_date_risk_days', 7);
    }

    /**
     * Whether the window above currently comes from the measured SLE. The
     * Kanban's chip and the Fluxo page read this to explain *why* an item
     * was promoted, instead of leaving the number unattributed.
     */
    public function riskWindowFromSle(): bool
    {
        return $this->metrics->sleDays() !== null;
    }

    /**
     * The queue, in pull order.
     *
     * @param  Closure(Builder): void|null  $constrain  Extra scoping applied to the universe (the Kanban's column filters).
     * @return Collection<int, PullQueueEntry>
     */
    public function queue(?Closure $constrain = null): Collection
    {
        $activities = $this->universe($constrain);

        if ($activities->isEmpty()) {
            return collect();
        }

        $history = $this->historyMap($activities);

        return $activities
            ->map(fn (Activity $activity): PullQueueEntry => $this->entryFor(
                $activity,
                $history[$activity->id] ?? [],
            ))
            ->sort(fn (PullQueueEntry $a, PullQueueEntry $b): int => $a->sortKey() <=> $b->sortKey())
            ->values();
    }

    /**
     * Whether a Data fixa is close enough to its due date to be promoted
     * above the FIFO degrau. Overdue counts as at risk.
     */
    public function isAtRisk(Activity $activity): bool
    {
        if ($activity->service_class !== ServiceClass::FixedDate || $activity->due_date === null) {
            return false;
        }

        return $this->daysToDueDate($activity) <= $this->riskWindowDays();
    }

    /**
     * The universe the queue ranks: the board items sitting in Pronto.
     *
     * @return EloquentCollection<int, Activity>
     */
    private function universe(?Closure $constrain): EloquentCollection
    {
        $query = Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Todo)
            ->with(['project.client', 'client', 'parent', 'timeEntries'])
            ->withCount('commits');

        if ($constrain !== null) {
            $constrain($query);
        }

        return $query->get();
    }

    /**
     * Build the entry for one activity: which degrau it lands on, and the
     * facts that justify it.
     *
     * @param  array{ready_at?: CarbonInterface, ready_sequence?: int, board_since?: CarbonInterface}  $history
     */
    private function entryFor(Activity $activity, array $history): PullQueueEntry
    {
        $readySince = $history['ready_at'] ?? $activity->created_at;
        $readySequence = $history['ready_sequence'] ?? 0;
        $boardSince = $history['board_since'] ?? null;

        $reason = match (true) {
            $activity->service_class === ServiceClass::Emergency => PullQueueReason::Emergency,
            $this->isAtRisk($activity) => PullQueueReason::FixedDateAtRisk,
            default => PullQueueReason::Fifo,
        };

        return new PullQueueEntry(
            $activity,
            $reason,
            $readySince,
            $activity->due_date === null ? null : $this->daysToDueDate($activity),
            $readySequence,
            $boardSince,
        );
    }

    /**
     * Whole days from today to the due date — negative when overdue.
     */
    private function daysToDueDate(Activity $activity): int
    {
        return (int) today()->diffInDays($activity->due_date->copy()->startOfDay(), false);
    }

    /**
     * The two facts the queue reads from the status history, for every
     * queued activity, in one query:
     *
     * - `ready_at` / `ready_sequence`: the *last* entry into Pronto and the
     *   id of the change that recorded it. The id is kept because
     *   `changed_at` only has second precision, so it is the only thing
     *   that can order two moves made within the same second.
     * - `board_since`: the *first* entry into any board column. Inbox is
     *   triage, not the board, so it doesn't start the clock — this is
     *   what makes "idade no quadro" mean what it says.
     *
     * @param  EloquentCollection<int, Activity>  $activities
     * @return array<int, array{ready_at?: CarbonInterface, ready_sequence?: int, board_since?: CarbonInterface}>
     */
    private function historyMap(EloquentCollection $activities): array
    {
        $boardStatuses = array_map(
            fn (ActivityStatus $status): string => $status->value,
            ActivityStatus::boardOrder()
        );

        return ActivityStatusChange::query()
            ->whereIn('activity_id', $activities->modelKeys())
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get(['id', 'activity_id', 'to_status', 'changed_at'])
            ->groupBy('activity_id')
            ->map(function (Collection $changes) use ($boardStatuses): array {
                $lastReady = $changes
                    ->filter(fn (ActivityStatusChange $change): bool => $change->to_status === ActivityStatus::Todo)
                    ->last();

                $firstOnBoard = $changes
                    ->first(fn (ActivityStatusChange $change): bool => in_array($change->to_status->value, $boardStatuses, true));

                return array_filter([
                    'ready_at' => $lastReady?->changed_at,
                    'ready_sequence' => $lastReady?->id,
                    'board_since' => $firstOnBoard?->changed_at,
                ], fn (mixed $value): bool => $value !== null);
            })
            ->all();
    }
}
