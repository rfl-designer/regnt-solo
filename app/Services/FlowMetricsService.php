<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The board's flow metrics: how long things actually take, and what the
 * board can therefore promise (issue #145).
 *
 * Everything here is *derived* from {@see ActivityStatusChange}. No column
 * on `activities` records a cycle time, and none should: the history is
 * the only record that survives an item being pushed back, re-pulled and
 * finished twice, and a stored number would need to be recomputed on every
 * one of those moves anyway.
 *
 * ## The clock
 *
 * Cycle time runs from the **first** entry into Pronto to the **last**
 * entry into Feito. Three consequences, each deliberate:
 *
 * - **Aguardando aprovação is outside the clock.** It happens *before*
 *   Pronto — the item isn't committed work yet, it's a proposal — so the
 *   clock hasn't started. This is what keeps a client sitting on a budget
 *   for three weeks from poisoning the board's numbers.
 * - **Esperando and Aguardando validação are inside it.** Both happen
 *   after the commitment. They are real delay in delivering something
 *   already promised, and hiding them would make the SLE a promise about
 *   the parts of the work that go well.
 * - **A round trip does not restart the clock.** An item bounced from
 *   Feito back to Fazendo and finished again took all of that time to
 *   deliver, so the *last* Feito closes it, and the *first* Pronto opens
 *   it.
 *
 * ## The promise
 *
 * The SLE is the configured percentile of the cycle times measured since
 * the most recent {@see BaselineCut}. It stays unquotable until the sample
 * reaches `soloboard.sle_minimum_sample` items — under that, every surface
 * says "amostra pequena (n=X)" and nothing derives a decision from it.
 * That refusal is the point: an under-powered percentile is a number that
 * *looks* like a commitment.
 */
class FlowMetricsService
{
    /** @var list<float>|null */
    private ?array $memoizedSample = null;

    /**
     * Clocks already read from the history, keyed by activity id. Missing
     * ends are kept as an empty array so an item with no clock is
     * remembered as "asked and there is nothing" instead of being queried
     * again on every card that renders it.
     *
     * @var array<int, array{started_at?: CarbonInterface, finished_at?: CarbonInterface}>
     */
    private array $clockCache = [];

    /**
     * The four columns where an item is already committed work and its
     * clock is running — the ones where aging means something. Backlog and
     * Aguardando aprovação are excluded for the same reason they are
     * outside the clock: nothing has been promised yet.
     *
     * @return list<ActivityStatus>
     */
    public static function agingStatuses(): array
    {
        return [
            ActivityStatus::Todo,
            ActivityStatus::Doing,
            ActivityStatus::Waiting,
            ActivityStatus::AwaitingValidation,
        ];
    }

    /**
     * Which percentile answers the promise. Read from config on every call
     * so the number quoted can never drift from the configured method.
     */
    public function percentile(): int
    {
        return (int) config('soloboard.sle_percentile', 85);
    }

    /**
     * How many measured items the percentile needs before it may be quoted.
     */
    public function minimumSample(): int
    {
        return (int) config('soloboard.sle_minimum_sample', 30);
    }

    /**
     * How far into the SLE an item gets before the board flags it, as a
     * percentage of the SLE.
     */
    public function attentionPercent(): int
    {
        return (int) config('soloboard.sle_attention_percent', 80);
    }

    /**
     * The cut the baseline is measured from — the most recent one.
     */
    public function lastCut(): ?BaselineCut
    {
        return BaselineCut::query()->latestFirst()->first();
    }

    /**
     * Record a cut. The motivo is required here rather than only in the
     * form, so no origin — MCP, tinker, a future importer — can leave the
     * history with an unexplained restart.
     *
     * @throws \InvalidArgumentException when the motivo is blank.
     */
    public function cut(string $reason, ?CarbonInterface $at = null): BaselineCut
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('Um corte de baseline exige um motivo.');
        }

        return BaselineCut::create([
            'reason' => $reason,
            'cut_at' => $at ?? now(),
        ]);
    }

    /**
     * The baseline population: the cycle times, in days, of everything
     * concluded since the last cut.
     *
     * An item concluded in the window but with no measurable clock — no
     * recorded entry into Pronto, typically because it was closed straight
     * out of triage — is left out rather than counted as zero. It has no
     * cycle time; inventing one would drag the percentile down.
     *
     * @return list<float>
     */
    public function sample(): array
    {
        if ($this->memoizedSample !== null) {
            return $this->memoizedSample;
        }

        $query = Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Done);

        $cutAt = $this->lastCut()?->cut_at;

        if ($cutAt !== null) {
            $query->where('completed_at', '>=', $cutAt);
        }

        /** @var EloquentCollection<int, Activity> $completed */
        $completed = $query->get(['id']);

        if ($completed->isEmpty()) {
            return $this->memoizedSample = [];
        }

        $clocks = $this->clocks($completed->modelKeys());

        return $this->memoizedSample = $completed
            ->map(fn (Activity $activity): ?float => $this->cycleTimeFromClock($clocks[$activity->id] ?? []))
            ->filter(fn (?float $days): bool => $days !== null)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * How many measured items the baseline holds — the "n" in "amostra
     * pequena (n=X)".
     */
    public function sampleSize(): int
    {
        return count($this->sample());
    }

    /**
     * Whether the SLE may be quoted and acted on.
     */
    public function isUsable(): bool
    {
        return $this->sampleSize() >= $this->minimumSample();
    }

    /**
     * The SLE in whole days — "N% dos itens em até Y dias" — or null while
     * the sample is too small to promise anything.
     *
     * Rounded *up*: a promise of "até 9 dias" derived from a measured 8.2
     * would be broken by the very item that produced it.
     */
    public function sleDays(): ?int
    {
        if (! $this->isUsable()) {
            return null;
        }

        $value = $this->percentileOf($this->sample(), $this->percentile());

        return $value === null ? null : max(1, (int) ceil($value));
    }

    /**
     * The one-line rendering of the promise, shared by every surface that
     * shows it so the Kanban chip and the Fluxo page can never disagree.
     */
    public function label(): string
    {
        $sle = $this->sleDays();

        if ($sle === null) {
            return "amostra pequena (n={$this->sampleSize()})";
        }

        return "SLE {$this->percentile()}% ≤ {$sle}d";
    }

    /**
     * The cycle time of one activity, in days, or null when the history
     * has no closed clock (never reached Pronto, or not finished yet).
     */
    public function cycleTimeDays(Activity $activity): ?float
    {
        return $this->cycleTimeFromClock($this->clocks([$activity->id])[$activity->id] ?? []);
    }

    /**
     * How long an unfinished activity has had its clock running: the same
     * clock as the cycle time, measured from the first entry into Pronto
     * to now. Null when it has never been in Pronto.
     */
    public function ageDays(Activity $activity): ?float
    {
        $startedAt = $this->clocks([$activity->id])[$activity->id]['started_at'] ?? null;

        return $startedAt === null ? null : $startedAt->floatDiffInDays(now());
    }

    /**
     * The aging verdict for one card, or null when there is nothing to
     * say — no usable SLE, or an item whose clock never started.
     *
     * "Sem baseline utilizável, sem alarme" is enforced right here: with a
     * small sample there is no Y to be 80% of, and a board that cries wolf
     * off an unpowered percentile teaches the user to ignore the colour.
     *
     * @return array{days: float, sle: int, ratio: float, level: string, tooltip: string}|null
     */
    public function agingFor(Activity $activity): ?array
    {
        $sle = $this->sleDays();

        if ($sle === null || ! in_array($activity->status, self::agingStatuses(), true)) {
            return null;
        }

        $days = $this->ageDays($activity);

        if ($days === null) {
            return null;
        }

        $ratio = $days / $sle;
        $level = match (true) {
            $ratio >= 1.0 => 'breach',
            $ratio >= $this->attentionPercent() / 100 => 'attention',
            default => 'ok',
        };

        return [
            'days' => $days,
            'sle' => $sle,
            'ratio' => $ratio,
            'level' => $level,
            'tooltip' => sprintf('%d de %d dias da SLE', (int) round($days), $sle),
        ];
    }

    /**
     * The border colour a card wears for its aging, written out in full:
     * Tailwind scans these templates as plain text, so an interpolated
     * `border-{$color}-500` would never be generated.
     */
    public function agingBorderClass(Activity $activity): ?string
    {
        return match ($this->agingFor($activity)['level'] ?? null) {
            'breach' => 'border-red-500',
            'attention' => 'border-amber-500',
            default => null,
        };
    }

    /**
     * The tooltip text for a card's aging, or null when it isn't flagged.
     */
    public function agingTooltip(Activity $activity): ?string
    {
        $aging = $this->agingFor($activity);

        return ($aging === null || $aging['level'] === 'ok') ? null : $aging['tooltip'];
    }

    /**
     * Everything on the board currently at or past the attention
     * threshold, oldest relative to the SLE first — the list the Fluxo
     * page shows as "o que está envelhecendo".
     *
     * @return Collection<int, array{activity: Activity, aging: array{days: float, sle: int, ratio: float, level: string, tooltip: string}}>
     */
    public function agingItems(): Collection
    {
        if (! $this->isUsable()) {
            return collect();
        }

        return Activity::query()
            ->leaf()
            ->whereIn('status', array_map(
                fn (ActivityStatus $status): string => $status->value,
                self::agingStatuses(),
            ))
            ->with(['project', 'client'])
            ->get()
            ->map(fn (Activity $activity): array => [
                'activity' => $activity,
                'aging' => $this->agingFor($activity),
            ])
            ->filter(fn (array $row): bool => $row['aging'] !== null && $row['aging']['level'] !== 'ok')
            ->sortByDesc(fn (array $row): float => $row['aging']['ratio'])
            ->values();
    }

    /**
     * The baseline's cycle times bucketed by whole day, ready for the
     * distribution chart. Empty days in the middle are kept so the shape
     * of the distribution — and its tail — is read honestly instead of
     * being compressed into a wall of adjacent bars.
     *
     * @return list<array{days: int, count: int}>
     */
    public function distribution(): array
    {
        $sample = $this->sample();

        if ($sample === []) {
            return [];
        }

        $buckets = collect($sample)
            ->map(fn (float $days): int => max(1, (int) ceil($days)))
            ->countBy()
            ->all();

        $max = max(array_keys($buckets));

        return collect(range(1, $max))
            ->map(fn (int $day): array => ['days' => $day, 'count' => $buckets[$day] ?? 0])
            ->all();
    }

    /**
     * The linear-interpolation percentile — the same definition a
     * spreadsheet's PERCENTILE() uses, so a number quoted here can be
     * checked by hand against the exported cycle times.
     *
     * @param  list<float>  $sorted  Ascending.
     */
    public function percentileOf(array $sorted, int $percentile): ?float
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return $sorted[0];
        }

        $rank = ($percentile / 100) * ($count - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        if ($low === $high) {
            return $sorted[$low];
        }

        return $sorted[$low] + ($rank - $low) * ($sorted[$high] - $sorted[$low]);
    }

    /**
     * Turn one activity's clock into a cycle time.
     *
     * @param  array{started_at?: CarbonInterface, finished_at?: CarbonInterface}  $clock
     */
    private function cycleTimeFromClock(array $clock): ?float
    {
        $started = $clock['started_at'] ?? null;
        $finished = $clock['finished_at'] ?? null;

        if ($started === null || $finished === null || $finished->lessThan($started)) {
            return null;
        }

        return $started->floatDiffInDays($finished);
    }

    /**
     * Read the clocks of a whole set of activities up front.
     *
     * A board renders dozens of cards and each one asks for its aging;
     * without this the answer would cost one query per card. Callers that
     * already hold the collection (the Kanban's columns) warm it once and
     * every subsequent lookup is free.
     *
     * @param  iterable<Activity>  $activities
     */
    public function warm(iterable $activities): void
    {
        $ids = [];

        foreach ($activities as $activity) {
            $ids[] = $activity->id;
        }

        $this->clocks($ids);
    }

    /**
     * The clocks for these ids, reading only the ones not seen yet.
     *
     * @param  list<int>  $activityIds
     * @return array<int, array{started_at?: CarbonInterface, finished_at?: CarbonInterface}>
     */
    private function clocks(array $activityIds): array
    {
        $missing = array_values(array_diff($activityIds, array_keys($this->clockCache)));

        if ($missing !== []) {
            $found = $this->clockMap($missing);

            foreach ($missing as $id) {
                $this->clockCache[$id] = $found[$id] ?? [];
            }
        }

        return array_intersect_key($this->clockCache, array_flip($activityIds));
    }

    /**
     * Read the two ends of the clock for many activities in one query: the
     * first entry into Pronto and the last entry into Feito.
     *
     * @param  list<int>  $activityIds
     * @return array<int, array{started_at?: CarbonInterface, finished_at?: CarbonInterface}>
     */
    private function clockMap(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        return ActivityStatusChange::query()
            ->whereIn('activity_id', $activityIds)
            ->whereIn('to_status', [ActivityStatus::Todo->value, ActivityStatus::Done->value])
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get(['id', 'activity_id', 'to_status', 'changed_at'])
            ->groupBy('activity_id')
            ->map(function (Collection $changes): array {
                $firstReady = $changes
                    ->first(fn (ActivityStatusChange $change): bool => $change->to_status === ActivityStatus::Todo);

                $lastDone = $changes
                    ->last(fn (ActivityStatusChange $change): bool => $change->to_status === ActivityStatus::Done);

                return array_filter([
                    'started_at' => $firstReady?->changed_at,
                    'finished_at' => $lastDone?->changed_at,
                ], fn (mixed $value): bool => $value !== null);
            })
            ->all();
    }
}
