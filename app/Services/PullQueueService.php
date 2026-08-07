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
 *    the due date is within `soloboard.fixed_date_risk_days` (an overdue
 *    one is at risk too). A Data fixa still comfortably far out is *not*
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
    /**
     * The risk window, in days. Read from config on every call so the
     * queue can never drift from the configured method.
     *
     * The SLE (Service Level Expectation) is meant to replace this number
     * once the board has enough measured lead time to compute one; that
     * substitution belongs to the metrics slice and deliberately isn't
     * implemented here.
     */
    public function riskWindowDays(): int
    {
        return (int) config('soloboard.fixed_date_risk_days', 7);
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

        $readySince = $this->readySinceMap($activities);

        return $activities
            ->map(fn (Activity $activity): PullQueueEntry => $this->entryFor(
                $activity,
                $readySince[$activity->id] ?? $activity->created_at,
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
     */
    private function entryFor(Activity $activity, CarbonInterface $readySince): PullQueueEntry
    {
        if ($activity->service_class === ServiceClass::Emergency) {
            return new PullQueueEntry($activity, PullQueueReason::Emergency, $readySince);
        }

        if ($this->isAtRisk($activity)) {
            return new PullQueueEntry(
                $activity,
                PullQueueReason::FixedDateAtRisk,
                $readySince,
                $this->daysToDueDate($activity),
            );
        }

        return new PullQueueEntry(
            $activity,
            PullQueueReason::Fifo,
            $readySince,
            $activity->due_date === null ? null : $this->daysToDueDate($activity),
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
     * The last entry into Pronto for each activity, in one query.
     *
     * @param  EloquentCollection<int, Activity>  $activities
     * @return array<int, CarbonInterface>
     */
    private function readySinceMap(EloquentCollection $activities): array
    {
        return ActivityStatusChange::query()
            ->whereIn('activity_id', $activities->modelKeys())
            ->where('to_status', ActivityStatus::Todo)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get(['id', 'activity_id', 'changed_at'])
            ->groupBy('activity_id')
            ->map(fn (Collection $changes): CarbonInterface => $changes->last()->changed_at)
            ->all();
    }
}
