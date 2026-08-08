<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\Client;
use Carbon\CarbonInterface;
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

        $cut = BaselineCut::create([
            'reason' => $reason,
            'cut_at' => $at ?? now(),
        ]);

        // A cut redefines the population, so anything already computed
        // from the old one is now a lie. Callers that keep the service
        // around — a Livewire component that cuts and re-renders in the
        // same request — must not have to know to throw it away.
        $this->forget();

        return $cut;
    }

    /**
     * Drop everything memoized from the current baseline.
     */
    public function forget(): void
    {
        $this->memoizedSample = null;
        $this->clockCache = [];
    }

    /**
     * The baseline population: the cycle times, in days, of everything
     * concluded since the last cut.
     *
     * "Concluded since the cut" is decided by the **last entry into Feito**,
     * not by `activities.completed_at`. Those two normally agree, and when
     * they don't the history is the one that counts: it is the timestamp
     * that closes the clock a few lines below, so filtering by anything
     * else would let an item be measured with one date and admitted with
     * another. A backfill, an import or a bulk write that skipped model
     * events is exactly the case where they diverge, and exactly the case
     * where a silently wrong n would move the SLE and the pull queue's
     * window with it.
     *
     * An item in the window with no measurable clock — no recorded entry
     * into Pronto, typically because it was closed straight out of triage
     * — is left out rather than counted as zero. It has no cycle time;
     * inventing one would drag the percentile down.
     *
     * Cost is three queries regardless of size, and none of them hydrates
     * an Activity: the population is read as ids. It still grows with
     * everything concluded since the cut — which is what a cut is *for*.
     *
     * @return list<float>
     */
    public function sample(): array
    {
        if ($this->memoizedSample !== null) {
            return $this->memoizedSample;
        }

        $cutAt = $this->lastCut()?->cut_at;

        // Anything that entered Feito after the cut. An item can only have
        // its *last* Feito after the cut if it has *some* Feito after it,
        // so this candidate set is exactly the population — no narrowing
        // pass needed once the clocks are read.
        $finishedSinceCut = ActivityStatusChange::query()
            ->where('to_status', ActivityStatus::Done->value)
            ->when($cutAt !== null, fn ($query) => $query->where('changed_at', '>=', $cutAt))
            ->distinct()
            ->pluck('activity_id')
            ->all();

        if ($finishedSinceCut === []) {
            return $this->memoizedSample = [];
        }

        // Still concluded, and still a board item: an item reopened after
        // the cut is work in progress again, and a parent that has since
        // grown children is a container, not a delivery.
        $ids = Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Done)
            ->whereIn('id', $finishedSinceCut)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return $this->memoizedSample = [];
        }

        $clocks = $this->clocks($ids);

        return $this->memoizedSample = collect($ids)
            ->map(fn (int $id): ?float => $this->cycleTimeFromClock($clocks[$id] ?? []))
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
            'tooltip' => sprintf('%s de %d dias da SLE', $this->formatDays($days, $level), $sle),
        ];
    }

    /**
     * The age as the tooltip states it, in PT-BR.
     *
     * Rounding to whole days would make the text contradict the colour it
     * sits on: with an SLE of 10, both 9,6 (amber — still inside) and 10,4
     * (red — already broken) would read "10 de 10 dias". A card that says
     * one thing and shows another trains the user to trust neither.
     *
     * So the age keeps a decimal, and it is rounded *away* from the
     * threshold — down while the item is still inside the SLE, up once it
     * has broken it. That makes the number and the border agree by
     * construction: an amber card can never read as having reached the
     * SLE, and a red one can never read as being short of it.
     */
    private function formatDays(float $days, string $level): string
    {
        $value = $level === 'breach'
            ? ceil($days * 10) / 10
            : floor($days * 10) / 10;

        return $value == (int) $value
            ? (string) (int) $value
            : number_format($value, 1, ',', '');
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

        $candidates = Activity::query()
            ->leaf()
            ->whereIn('status', array_map(
                fn (ActivityStatus $status): string => $status->value,
                self::agingStatuses(),
            ))
            ->with(['project', 'client'])
            ->get();

        // One history read for the whole list. Without this, asking each
        // row for its aging is a query per row.
        $this->warm($candidates);

        return $candidates
            ->map(fn (Activity $activity): array => [
                'activity' => $activity,
                'aging' => $this->agingFor($activity),
            ])
            ->filter(fn (array $row): bool => $row['aging'] !== null && $row['aging']['level'] !== 'ok')
            ->sortByDesc(fn (array $row): float => $row['aging']['ratio'])
            ->values();
    }

    /**
     * How long each validated Spec was a live bet, in days — the population
     * the apetite aside compares a budget against (issue #148).
     *
     * This is *not* {@see sample()}. The cycle-time baseline measures cards
     * from Pronto to Feito; a bet is measured over the window it was actually
     * a commitment in, aprovada -> validada, which is the same window the
     * apetite is a budget for. Comparing a 7-day apetite against the cycle
     * time of individual cards would compare a bet with its parts.
     *
     * Only Specs that are validated *and have stayed that way* count: an
     * Épico reopened after validation is a live bet again, and its window is
     * still open. Everything is read against the last baseline cut, so a cut
     * resets this history exactly as it resets the SLE.
     *
     * @return list<float> Ascending.
     */
    public function validatedSpecSample(): array
    {
        $cutAt = $this->lastCut()?->cut_at;

        return Activity::query()
            ->epics()
            ->where('status', ActivityStatus::Done)
            ->with('statusChanges')
            ->get()
            ->filter(fn (Activity $epic): bool => $epic->isSpecValidated() && $epic->spec_aprovada !== null)
            ->filter(fn (Activity $epic): bool => $cutAt === null || $epic->spec_validada->greaterThanOrEqualTo($cutAt))
            ->map(fn (Activity $epic): float => $epic->spec_aprovada->floatDiffInDays($epic->spec_validada))
            ->filter(fn (float $days): bool => $days >= 0)
            ->sort()
            ->values()
            ->all();
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
     * The flow efficiency of one Épico's Spec: how much of the time between
     * the approval and the validation was actually spent touching the work
     * (issue #146).
     *
     * ## The window
     *
     * `spec_aprovada` -> `spec_validada`, and it runs to *now* while the
     * Spec has not been validated yet, so an Épico rotting in Aguardando
     * validação watches its own efficiency fall instead of staying
     * flattering until the day it closes.
     *
     * Aprovação is outside the window on purpose — same reason Aguardando
     * aprovação is outside the cycle-time clock: nothing had been committed
     * to yet. Every wait *after* the approval is inside it. That asymmetry
     * is the whole measurement: the number is low because of the waiting,
     * and hiding the waiting would make it meaningless.
     *
     * ## The touch
     *
     * The union of the intervals in which some child sat in Fazendo — or
     * the Épico itself, when it is atomic and therefore its own only card.
     * Union, not sum: with a WIP of 2 two children are routinely in Fazendo
     * at the same time, and adding their hours would produce efficiencies
     * over 100%, which is not a thing. Overlaps are merged, so the touch is
     * "how much calendar time had *something* moving", and it can never
     * exceed the window it is measured inside.
     *
     * Returns null when the Spec has never been approved: there is no
     * window to measure, and a zero would read as "nothing was done".
     *
     * @return array{window_start: CarbonInterface, window_end: CarbonInterface, open: bool, window_minutes: float, touch_minutes: float, ratio: float, percent: int}|null
     */
    public function specEfficiency(Activity $epic): ?array
    {
        $start = $epic->spec_aprovada;

        if ($start === null) {
            return null;
        }

        // The window closes on the validation only while the Spec is
        // *still* validated. A validated Épico that was reopened — or
        // delivered again after being rejected — has a validation date in
        // its past and live work in its present, and closing on that stale
        // date would throw away every hour since it and every day of the
        // wait that is running right now. {@see Activity::specStage()} is
        // what tells the two apart, by walking the history in order rather
        // than asking which timestamps exist.
        $validatedAt = $epic->isSpecValidated() ? $epic->spec_validada : null;
        $open = $validatedAt === null || $validatedAt->lessThan($start);
        $end = $open ? now() : $validatedAt;

        $windowMinutes = max(0.0, $start->floatDiffInMinutes($end));

        // The atomic Épico is its own card: nobody else can be in Fazendo
        // on its behalf, so its own history is the touch.
        $childIds = $epic->children()->pluck('id')->all();
        $touchedIds = $childIds === [] ? [$epic->id] : $childIds;

        $doing = $this->statusIntervals($touchedIds, [ActivityStatus::Doing]);

        $intervals = $this->mergeIntervals($this->clipIntervals(
            array_merge(...array_values($doing)),
            $start,
            $end,
        ));

        $touchMinutes = 0.0;

        foreach ($intervals as [$from, $to]) {
            $touchMinutes += $from->floatDiffInMinutes($to);
        }

        $ratio = $windowMinutes > 0 ? min(1.0, $touchMinutes / $windowMinutes) : 0.0;

        return [
            'window_start' => $start,
            'window_end' => $end,
            'open' => $open,
            'window_minutes' => $windowMinutes,
            'touch_minutes' => $touchMinutes,
            'ratio' => $ratio,
            'percent' => (int) round($ratio * 100),
        ];
    }

    /**
     * How long each client kept the board waiting, over the last $days
     * (issue #146).
     *
     * Only the two client-side waits count — Aguardando aprovação and
     * Aguardando validação. **Esperando is deliberately out**: it is an
     * internal wait, and putting it on a client's tab would turn the
     * ranking from "quem me faz esperar" into "onde eu esperei", which is a
     * different (and much less useful) question.
     *
     * Any Activity counts, Épico or card, because both kinds sit in front
     * of a client. Waits still open count up to now, so a client sitting on
     * an approval right now climbs the ranking while they sit on it rather
     * than only once they answer. Intervals are clipped to the window, so
     * a wait that started before it contributes only its share.
     *
     * Activities with no effective client are left out: they are real
     * waits, but not attributable to anyone, and inventing a bucket for
     * them would misread as a client.
     *
     * @return Collection<int, array{client: Client, minutes: float, items: int}>
     */
    public function clientWaitRanking(int $days = 30): Collection
    {
        $windowStart = now()->copy()->subDays($days);
        $windowEnd = now();

        $statuses = array_values(array_filter(
            ActivityStatus::cases(),
            fn (ActivityStatus $status): bool => $status->isClientWaiting(),
        ));

        $waitingValues = array_map(fn (ActivityStatus $status): string => $status->value, $statuses);

        // Only the activities that can possibly contribute to *this* window.
        // Reading every activity that has ever waited would grow the cost of
        // a 30-day question with the whole history of the board.
        //
        // Two ways in, and both are needed. A wait that started or ended
        // inside the window leaves a change inside it — hence the
        // `from_status` half, which is what catches a wait that began before
        // the window and was answered during it. A wait that began before
        // the window and is *still* open leaves no change inside it at all,
        // and is caught by the activity sitting in the status right now.
        $candidateIds = ActivityStatusChange::query()
            ->where('changed_at', '>=', $windowStart)
            ->where(fn ($query) => $query
                ->whereIn('to_status', $waitingValues)
                ->orWhereIn('from_status', $waitingValues))
            ->distinct()
            ->pluck('activity_id')
            ->all();

        $candidateIds = array_values(array_unique(array_merge(
            $candidateIds,
            Activity::query()->whereIn('status', $waitingValues)->pluck('id')->all(),
        )));

        if ($candidateIds === []) {
            return collect();
        }

        $intervals = $this->statusIntervals($candidateIds, $statuses);

        $activities = Activity::query()
            ->whereIn('id', array_keys($intervals))
            ->with(['project.client', 'client'])
            ->get();

        $rows = [];

        foreach ($activities as $activity) {
            $client = $activity->effective_client;

            if ($client === null) {
                continue;
            }

            $minutes = 0.0;

            foreach ($this->clipIntervals($intervals[$activity->id], $windowStart, $windowEnd) as [$from, $to]) {
                $minutes += $from->floatDiffInMinutes($to);
            }

            if ($minutes <= 0.0) {
                continue;
            }

            $row = $rows[$client->id] ?? ['client' => $client, 'minutes' => 0.0, 'items' => 0];
            $row['minutes'] += $minutes;
            $row['items']++;
            $rows[$client->id] = $row;
        }

        return collect($rows)
            ->sortByDesc('minutes')
            ->values();
    }

    /**
     * Every interval in which the given activities sat in one of the given
     * statuses, read from the history in a single query.
     *
     * An interval closes at the next status change, or at *now* when the
     * activity is still sitting there — an open wait is still a wait, and
     * dropping it would make the longest ones invisible precisely while
     * they hurt.
     *
     * @param  list<int>  $activityIds
     * @param  list<ActivityStatus>  $statuses
     * @return array<int, list<array{0: CarbonInterface, 1: CarbonInterface}>>
     */
    private function statusIntervals(array $activityIds, array $statuses): array
    {
        if ($activityIds === []) {
            return [];
        }

        $wanted = array_map(fn (ActivityStatus $status): string => $status->value, $statuses);

        return ActivityStatusChange::query()
            ->whereIn('activity_id', $activityIds)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get(['id', 'activity_id', 'to_status', 'changed_at'])
            ->groupBy('activity_id')
            ->map(function (Collection $changes) use ($wanted): array {
                $ordered = $changes->values();
                $intervals = [];

                foreach ($ordered as $index => $change) {
                    if (! in_array($change->to_status->value, $wanted, true)) {
                        continue;
                    }

                    $end = $ordered->get($index + 1)?->changed_at ?? now();

                    if ($end->greaterThan($change->changed_at)) {
                        $intervals[] = [$change->changed_at, $end];
                    }
                }

                return $intervals;
            })
            ->filter(fn (array $intervals): bool => $intervals !== [])
            ->all();
    }

    /**
     * Cut every interval down to the part that falls inside the window,
     * dropping the ones that fall entirely outside it.
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $intervals
     * @return list<array{0: CarbonInterface, 1: CarbonInterface}>
     */
    private function clipIntervals(array $intervals, CarbonInterface $start, CarbonInterface $end): array
    {
        $clipped = [];

        foreach ($intervals as [$from, $to]) {
            $from = $from->lessThan($start) ? $start : $from;
            $to = $to->greaterThan($end) ? $end : $to;

            if ($to->greaterThan($from)) {
                $clipped[] = [$from, $to];
            }
        }

        return $clipped;
    }

    /**
     * Fuse overlapping (and touching) intervals into the union they cover.
     *
     * This is what keeps "toque" honest when two children are in Fazendo at
     * once: the same hour of the calendar is counted once, so the ratio can
     * never exceed 1 no matter how many things are in progress.
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $intervals
     * @return list<array{0: CarbonInterface, 1: CarbonInterface}>
     */
    private function mergeIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        usort($intervals, fn (array $a, array $b): int => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        $merged = [array_shift($intervals)];

        foreach ($intervals as [$from, $to]) {
            $last = count($merged) - 1;

            if ($from->lessThanOrEqualTo($merged[$last][1])) {
                if ($to->greaterThan($merged[$last][1])) {
                    $merged[$last][1] = $to;
                }

                continue;
            }

            $merged[] = [$from, $to];
        }

        return $merged;
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
     * Read the clocks of every card that can possibly show aging, in one
     * go — the Kanban's single warm-up call.
     *
     * The board renders seven columns, each split into "com projeto", "sem
     * projeto" and (for Pronto) the pull queue. Warming those pieces one
     * by one costs a query each, including for the three columns that
     * never show aging at all. This reads the four columns that can, as
     * ids, in two queries flat — independent of how many cards or columns
     * end up rendering.
     *
     * It returns early when there is no usable SLE, because then no card
     * is flagged and there is nothing worth reading.
     */
    public function warmBoard(): void
    {
        if ($this->sleDays() === null) {
            return;
        }

        $this->clocks(
            Activity::query()
                ->leaf()
                ->whereIn('status', array_map(
                    fn (ActivityStatus $status): string => $status->value,
                    self::agingStatuses(),
                ))
                ->pluck('id')
                ->all()
        );
    }

    /**
     * Read the clocks of a given set of activities up front.
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
