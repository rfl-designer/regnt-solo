<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\Client;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
            'tooltip' => sprintf('%s de %d dias da SLE', $this->formatDays($days, roundUp: $level === 'breach'), $sle),
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
     *
     * The apetite bar reads the same way against its own budget (issue #152),
     * which is why the rule is a parameter here rather than a second copy
     * there: "9 de 14 dias" and "13 de 10 dias" have to round the way the
     * colour they sit on does.
     */
    private function formatDays(float $days, bool $roundUp): string
    {
        $value = $roundUp
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
        $cut = $this->lastCut();

        // The shaping page reads this on every autosave, so the walk below —
        // every finished Épico, with its whole history hydrated — cannot run
        // per keystroke. The key carries the cut and the shape of the history
        // itself, so a new status change or a new cut produces a different key
        // rather than a stale answer: there is nothing to invalidate by hand.
        $key = sprintf(
            'flow.validated-spec-sample.%s.%d.%d',
            $cut?->id ?? 'none',
            ActivityStatusChange::query()->max('id') ?? 0,
            ActivityStatusChange::query()->count(),
        );

        return Cache::remember($key, now()->addHour(), fn (): array => $this->computeValidatedSpecSample($cut?->cut_at));
    }

    /**
     * The walk itself.
     *
     * Only Épicos that ever reached Aguardando aprovação can produce an
     * aprovada -> validada window, so the rest are not worth hydrating, and
     * the history is read with just the columns {@see Activity::specStage()}
     * actually looks at.
     *
     * @return list<float> Ascending.
     */
    private function computeValidatedSpecSample(?CarbonInterface $cutAt): array
    {
        return Activity::query()
            ->epics()
            ->where('status', ActivityStatus::Done)
            ->whereHas('statusChanges', fn ($query) => $query
                ->where('to_status', ActivityStatus::AwaitingApproval->value))
            ->with(['statusChanges' => fn ($query) => $query
                ->select(['id', 'activity_id', 'from_status', 'to_status', 'changed_at'])])
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
     * How much of an Épico's apetite the bet has already eaten (issue #152).
     *
     * ## The window is the same one
     *
     * Aprovada -> validada, and it runs to *now* while the Spec has not been
     * validated yet — byte for byte the window {@see specEfficiency()} measures
     * in, and for the same reason: the apetite is a budget for the time the
     * bet is a live commitment, and the approval is what turns it into one.
     * Nothing here is stored; the window comes out of the status history, so
     * an Épico reopened after being validated is a live bet again and its
     * consumption starts moving again on its own.
     *
     * Returns null when the Spec has never been approved: there is no bet
     * running, and a zero would read as "nothing spent" on something that has
     * not started.
     *
     * ## The thresholds are the aging's
     *
     * {@see attentionPercent()} for âmbar, 100% for vermelho — the same two
     * numbers a card wears against the SLE. A second scale would mean the board
     * flags lateness one way and overspending another.
     *
     * ## No apetite is a silent state, not a zero
     *
     * An Épico promoted before the apetite existed (or one whose value fell out
     * of range) reports `no_appetite`: no ratio, no excess, and every surface
     * shows it without a bar and without an alarm. Comparing against a budget
     * nobody chose would be inventing the budget.
     *
     * @return array{appetite_days: int|null, consumed_days: float, ratio: float|null, over_days: float|null, over_label: string|null, headline: string|null, level: string, open: bool, window_start: CarbonInterface, window_end: CarbonInterface, label: string}|null
     */
    public function appetiteConsumption(Activity $epic): ?array
    {
        $start = $epic->spec_aprovada;

        if ($start === null) {
            return null;
        }

        // Same reading as the flow efficiency: the validation only closes the
        // window while the Spec is *still* validated.
        $validatedAt = $epic->isSpecValidated() ? $epic->spec_validada : null;
        $open = $validatedAt === null || $validatedAt->lessThan($start);
        $end = $open ? now() : $validatedAt;

        $consumed = max(0.0, $start->floatDiffInDays($end));

        // Zero, negative or absent is not an apetite. The upper bound is the
        // column's and is enforced on write ({@see ShapingService::normalizeAppetite()}).
        $appetite = ($epic->appetite_days !== null && $epic->appetite_days >= 1)
            ? (int) $epic->appetite_days
            : null;

        if ($appetite === null) {
            return [
                'appetite_days' => null,
                'consumed_days' => $consumed,
                'ratio' => null,
                'over_days' => null,
                'over_label' => null,
                'level' => 'no_appetite',
                'open' => $open,
                'window_start' => $start,
                'window_end' => $end,
                'label' => 'sem apetite definido',
            ];
        }

        $ratio = $consumed / $appetite;
        $level = match (true) {
            $ratio >= 1.0 => 'exceeded',
            $ratio >= $this->attentionPercent() / 100 => 'warning',
            default => 'ok',
        };

        $over = max(0.0, $consumed - $appetite);

        // Exatamente no limite não há excedente para mostrar: `+0d` seria um
        // estouro que não existe. O vermelho continua nos 100% — o orçamento
        // acabou —, mas a frase muda de "estourado" para "no limite". Qualquer
        // excedente positivo, por menor que seja, arredonda para cima e vira
        // pelo menos `+0,1d`, então este ramo só pega o zero exato.
        $overLabel = ($level === 'exceeded' && $over > 0)
            ? '+'.$this->formatDays($over, roundUp: true).'d'
            : null;

        return [
            'appetite_days' => $appetite,
            'consumed_days' => $consumed,
            'ratio' => $ratio,
            'over_days' => $over,
            'over_label' => $overLabel,
            'headline' => match (true) {
                $level !== 'exceeded' => null,
                $overLabel !== null => "Apetite estourado ({$overLabel})",
                default => 'Apetite no limite',
            },
            'level' => $level,
            'open' => $open,
            'window_start' => $start,
            'window_end' => $end,
            'label' => sprintf(
                '%s de %d dias',
                $this->formatDays($consumed, roundUp: $level === 'exceeded'),
                $appetite,
            ),
        ];
    }

    /**
     * Every bet currently running — the "Apostas em andamento" list on the
     * Fluxo page (issue #152).
     *
     * A bet is running when its Spec has been approved and not validated:
     * stages `aprovada` and `entregue`. An Épico still waiting for the yes is
     * not a bet yet, and a validated one is a bet that closed.
     *
     * Ordered the way the page reads it: estourados first, then by how much of
     * the apetite is gone, with the ones that never chose an apetite last —
     * they have nothing to be ranked by, and putting them anywhere in the
     * middle would suggest they do.
     *
     * @return Collection<int, array{epic: Activity, consumption: array{appetite_days: int|null, consumed_days: float, ratio: float|null, over_days: float|null, over_label: string|null, headline: string|null, level: string, open: bool, window_start: CarbonInterface, window_end: CarbonInterface, label: string}}>
     */
    public function liveBets(): Collection
    {
        $ranks = ['exceeded' => 0, 'warning' => 1, 'ok' => 2, 'no_appetite' => 3];

        return Activity::query()
            ->epics()
            ->whereHas('statusChanges', fn ($query) => $query
                ->where('to_status', ActivityStatus::AwaitingApproval->value))
            ->with(['project', 'client', 'statusChanges'])
            ->get()
            ->map(fn (Activity $epic): array => [
                'epic' => $epic,
                'consumption' => $this->appetiteConsumption($epic),
                'stage' => $epic->specStage(),
            ])
            ->filter(fn (array $row): bool => $row['consumption'] !== null
                && in_array($row['stage'], ['aprovada', 'entregue'], true))
            ->sortBy([
                fn (array $a, array $b): int => $ranks[$a['consumption']['level']] <=> $ranks[$b['consumption']['level']],
                fn (array $a, array $b): int => ($b['consumption']['ratio'] ?? 0.0) <=> ($a['consumption']['ratio'] ?? 0.0),
            ])
            ->map(fn (array $row): array => ['epic' => $row['epic'], 'consumption' => $row['consumption']])
            ->values();
    }

    /**
     * How many days without concluding an Intangível the board tolerates
     * before it says so.
     */
    public function intangibleStarvationDays(): int
    {
        return (int) config('soloboard.intangible_starvation_days', 14);
    }

    /**
     * The intangible hunger: how long the board has gone without *finishing*
     * an item classified as Intangível (issue #153).
     *
     * ## The clock is a conclusion, not a pull
     *
     * The last entry into Feito of an Intangível, read from
     * {@see ActivityStatusChange} like every other metric here. Pulling one
     * into Fazendo does not reset it, on purpose: Intangível is the class
     * that only pays off when it *lands*, and a counter satisfied by
     * starting would be silenced forever by an item parked in Fazendo.
     *
     * The last conclusion counts even when it happened before the current
     * baseline cut. A cut redefines the population the SLE is measured over;
     * it does not un-happen a refactor, and re-anchoring on the cut would
     * quietly reset the hunger of a board that has been starving for months.
     *
     * ## Cold start is maximum hunger, not missing data
     *
     * With no Intangível conclusion in the history at all, the clock anchors
     * on the current {@see BaselineCut} — "nenhuma conclusão desde o corte,
     * há X dias" — and the alert fires *regardless of the threshold*, even on
     * a cut made today. "Nunca concluí um Intangível" is the hungriest the
     * board can be; the threshold measures the age of a real conclusion, and
     * there is none to age. Gating the cold start on it would buy a
     * freshly-cut board two silent weeks precisely while it has never
     * concluded one. The days reported still come from the cut, so the card
     * says how long the board has been watched — but they decide nothing.
     *
     * A board with no cut yet anchors on the first status change it ever
     * recorded, which is the day it started running, and starves the same
     * way; a board with no history at all has no clock and stays silent.
     *
     * ## An empty pantry does not silence it
     *
     * `ready_count` reports how many Intangíveis are sitting in Pronto, and
     * zero is *not* a reason to suppress the alert — it only changes the
     * remedy from "puxe um" to "crie ou promova um". Suppressing it would
     * hand the board a way to stay quiet by never creating this class of
     * work at all.
     *
     * @return array{days: float, days_label: string, threshold: int, starving: bool, anchor: string, last_completed_at: CarbonInterface|null, ready_count: int, label: string, headline: string|null}
     */
    public function intangibleStarvation(): array
    {
        $threshold = $this->intangibleStarvationDays();
        $lastCompletedAt = $this->lastIntangibleConclusion();

        // O corte só é consultado na partida a frio, e uma vez só: a página
        // do Fluxo lê esta métrica junto com todas as outras, e uma segunda
        // chamada aqui seria mais uma query por render.
        $cutAt = $lastCompletedAt === null ? $this->lastCut()?->cut_at : null;

        $anchoredAt = $lastCompletedAt ?? $cutAt ?? $this->firstRecordedChange();
        $anchor = match (true) {
            $lastCompletedAt !== null => 'completion',
            $cutAt !== null => 'cut',
            $anchoredAt !== null => 'board',
            default => 'none',
        };

        $days = $anchoredAt === null ? 0.0 : max(0.0, $anchoredAt->floatDiffInDays(now()));

        // O limiar mede a idade de uma conclusão real. Na partida a frio não
        // há o que medir — a idade exibida é a do corte, que só diz desde
        // quando o quadro está sendo observado — e o alerta dispara de
        // qualquer jeito, inclusive num corte feito hoje. Se dependesse do
        // limiar, um quadro recém-cortado passaria duas semanas em silêncio
        // justamente enquanto nunca concluiu um Intangível.
        $coldStart = $anchor === 'cut' || $anchor === 'board';
        $starving = $coldStart || ($anchor === 'completion' && $days >= $threshold);

        $daysLabel = $this->formatDays($days, roundUp: $starving);
        $sameDay = $days < 1.0;

        $readyCount = Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Todo)
            ->where('service_class', ServiceClass::Intangible->value)
            ->count();

        $label = match ($anchor) {
            'completion' => "última conclusão há {$daysLabel} dias",
            'cut' => $sameDay
                ? 'nenhuma conclusão desde o corte, feito hoje'
                : "nenhuma conclusão desde o corte, há {$daysLabel} dias",
            'board' => $sameDay
                ? 'nenhuma conclusão registrada desde que o quadro começou, hoje'
                : "nenhuma conclusão registrada, há {$daysLabel} dias",
            default => 'nenhuma conclusão e nenhum histórico ainda',
        };

        return [
            'days' => $days,
            'days_label' => $daysLabel,
            'threshold' => $threshold,
            'starving' => $starving,
            'anchor' => $anchor,
            'last_completed_at' => $lastCompletedAt,
            'ready_count' => $readyCount,
            'label' => $label,
            'headline' => $starving
                ? "Fome de Intangível: {$label}"
                : null,
        ];
    }

    /**
     * Every Intangível sitting in Pronto, in the order the pull queue would
     * read them — oldest in Pronto first. The shortcut the ritual's banner
     * offers, so the remedy is one click from the alert.
     *
     * @return Collection<int, Activity>
     */
    public function readyIntangibles(): Collection
    {
        return Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Todo)
            ->where('service_class', ServiceClass::Intangible->value)
            ->with(['project', 'client'])
            ->get()
            ->sortBy(fn (Activity $activity): string => (string) $activity->created_at)
            ->values();
    }

    /**
     * When an Intangível last entered Feito, over the whole history.
     */
    private function lastIntangibleConclusion(): ?CarbonInterface
    {
        $at = ActivityStatusChange::query()
            ->where('to_status', ActivityStatus::Done->value)
            ->whereHas('activity', fn ($query) => $query
                ->where('service_class', ServiceClass::Intangible->value))
            ->max('changed_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /**
     * The first status change the board ever recorded — the day it started
     * running, and the only honest anchor for a board with no cut yet.
     */
    private function firstRecordedChange(): ?CarbonInterface
    {
        $at = ActivityStatusChange::query()->min('changed_at');

        return $at === null ? null : Carbon::parse($at);
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
