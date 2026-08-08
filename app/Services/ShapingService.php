<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Exceptions\ShapingIncompleteException;
use App\Models\Activity;
use Illuminate\Support\Str;

/**
 * Shaping: giving an Ideia enough form to be worth betting on, and the
 * promotion that turns it into an Épico (issue #148).
 *
 * ## Promotion is in place
 *
 * The Ideia does not seed an Épico — it *becomes* one. The same row changes
 * type, so everything already written during shaping (the dor, the esboço,
 * the apetite) is still there afterwards, under the same id, and the pitch
 * rendered from the Épico is the pitch rendered from the Ideia. A copy would
 * have needed a rule for which of the two is the real one the first time
 * either was edited.
 *
 * That is also why Dor is `description` and Esboço is `spec` rather than
 * columns of their own: those are the fields the Épico already shows.
 *
 * ## The bar for promoting
 *
 * Dor + Apetite + Esboço + projeto, and nothing else. The two optional
 * sections are optional on purpose — rabbit holes and no-gos are the parts
 * you often only discover you have none of, and demanding them would teach
 * the user to type something to get past the gate.
 *
 * The bar lives here, not in the page, so the MCP tool refuses with exactly
 * the same words as the button ({@see ShapingIncompleteException}).
 *
 * ## The apetite aside never blocks
 *
 * {@see appetiteHistory()} compares the chosen budget against what past bets
 * actually took. It is a mirror, not a gate: it has no failure mode, it never
 * appears in {@see missingForPromotion()}, and under
 * {@see MINIMUM_APPETITE_SAMPLE} validated Specs it says so instead of
 * quoting a percentile computed from two data points.
 */
class ShapingService
{
    /**
     * How many validated Specs the aside needs before it will quote a number.
     * Three is not a statistically meaningful sample — it is the floor below
     * which the aside would be quoting noise back at the user as if it were
     * their history.
     */
    public const int MINIMUM_APPETITE_SAMPLE = 3;

    /**
     * The chips, in dias corridos. "Outro" is not in here: it is the escape
     * hatch, not a value.
     *
     * @var list<int>
     */
    public const array APPETITE_CHIPS = [3, 7, 14, 21];

    public function __construct(private readonly FlowMetricsService $metrics) {}

    /**
     * The five sections, in the order the page stacks them, each with the
     * content it currently holds.
     *
     * This is the single enumeration of what shaping *is*: the page renders
     * it, the progress pips count it, and the pitch is written from it. None
     * of them may keep a list of their own.
     *
     * @return list<array{key: string, label: string, value: string|null, filled: bool, required: bool}>
     */
    public function sections(Activity $activity): array
    {
        $appetite = $activity->appetite_days;

        $rows = [
            ['key' => 'dor', 'label' => 'Dor', 'value' => $this->normalize($activity->description), 'required' => true],
            ['key' => 'apetite', 'label' => 'Apetite', 'value' => $appetite === null ? null : $this->appetiteLabel($appetite), 'required' => true],
            ['key' => 'esboco', 'label' => 'Esboço', 'value' => $this->normalize($activity->spec), 'required' => true],
            ['key' => 'rabbit_holes', 'label' => 'Rabbit holes', 'value' => $this->normalize($activity->rabbit_holes), 'required' => false],
            ['key' => 'no_gos', 'label' => 'No-gos', 'value' => $this->normalize($activity->no_gos), 'required' => false],
        ];

        return array_map(
            fn (array $row): array => [...$row, 'filled' => $row['value'] !== null],
            $rows,
        );
    }

    /**
     * How many of the five sections have something in them — the "X/5" on
     * the Ideias card and the lit pips in the shaping footer.
     */
    public function progress(Activity $activity): int
    {
        return count(array_filter($this->sections($activity), fn (array $s): bool => $s['filled']));
    }

    /**
     * Whether this Ideia has been worked on at all — what puts it under "Com
     * forma" instead of "Brutas".
     *
     * One section is enough. The point of the split is to not lose partial
     * shaping, and a draft with a dor written and nothing else is exactly the
     * partial that would otherwise get lost among the raw captures.
     */
    public function isShaped(Activity $activity): bool
    {
        return $this->progress($activity) > 0;
    }

    /**
     * What is still missing before this Ideia may be promoted, in PT-BR, in
     * the order the page shows the sections. Empty means it may.
     *
     * @return list<string>
     */
    public function missingForPromotion(Activity $activity, ?int $projectId = null): array
    {
        $missing = [];

        foreach ($this->sections($activity) as $section) {
            if ($section['required'] && ! $section['filled']) {
                $missing[] = $section['label'];
            }
        }

        if (($projectId ?? $activity->project_id) === null) {
            $missing[] = 'Projeto';
        }

        return $missing;
    }

    /**
     * Turn the Ideia into an Épico in Backlog.
     *
     * The Épico is born with an empty Spec lifecycle, and that falls out of
     * the design rather than being arranged: the four Spec dates are derived
     * from entries into Aguardando aprovação / validação / Feito
     * ({@see Activity::specStage()}), and being born into Backlog produces
     * none of them. Nothing here writes a lifecycle date, because there is
     * no column to write one to.
     *
     * @throws ShapingIncompleteException when Dor, Apetite, Esboço or projeto is missing.
     * @throws \InvalidArgumentException when the activity is not an Ideia.
     */
    public function promote(Activity $draft, ?int $projectId = null): Activity
    {
        if ($draft->type !== ActivityType::Draft) {
            throw new \InvalidArgumentException('Só uma Ideia pode ser promovida a Épico.');
        }

        $projectId ??= $draft->project_id;
        $missing = $this->missingForPromotion($draft, $projectId);

        if ($missing !== []) {
            throw new ShapingIncompleteException($missing);
        }

        $draft->forceFill([
            'type' => ActivityType::Epic,
            'status' => ActivityStatus::Backlog,
            'project_id' => $projectId,
            'slug' => $draft->slug ?: Str::slug($draft->title),
        ])->save();

        return $draft->refresh();
    }

    /**
     * The pitch, as markdown.
     *
     * Deterministic and total: the same five headings in the same order every
     * time, whatever is or isn't filled in. Sections are never dropped when
     * empty — a pitch whose shape changed with its content would be unusable
     * as the thing you paste into a conversation, and an empty "No-gos" is
     * itself worth reading.
     *
     * Rendered on demand and stored nowhere, so it cannot go stale.
     */
    public function pitch(Activity $activity): string
    {
        $lines = ["# {$activity->title}", ''];

        foreach ($this->sections($activity) as $section) {
            $lines[] = "## {$section['label']}";
            $lines[] = '';
            $lines[] = $section['value'] ?? '_(vazio)_';
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * How the chosen budget compares with what bets have actually taken.
     *
     * The population is the Specs validated since the last baseline cut, each
     * measured over the window it was a live bet in — aprovada -> validada
     * ({@see FlowMetricsService::validatedSpecSample()}). That is the same
     * window the apetite is a budget *for*, which is what makes the
     * comparison mean anything.
     *
     * `percentile` reads "your budget covers N% of what you have actually
     * delivered". A low number is not a refusal — it is the thing worth
     * seeing before you commit.
     *
     * @return array{sample_size: int, has_history: bool, median: float|null, p85: float|null, percentile: int|null, message: string}
     */
    public function appetiteHistory(?int $appetiteDays = null): array
    {
        $sample = $this->metrics->validatedSpecSample();
        $count = count($sample);

        if ($count < self::MINIMUM_APPETITE_SAMPLE) {
            return [
                'sample_size' => $count,
                'has_history' => false,
                'median' => null,
                'p85' => null,
                'percentile' => null,
                'message' => "Ainda sem histórico — {$count} spec(s) validada(s) desde o corte.",
            ];
        }

        $median = $this->metrics->percentileOf($sample, 50);
        $p85 = $this->metrics->percentileOf($sample, $this->metrics->percentile());

        $percentile = $appetiteDays === null
            ? null
            : (int) round(count(array_filter($sample, fn (float $days): bool => $days <= $appetiteDays)) / $count * 100);

        $message = sprintf(
            'Suas specs validadas: mediana %s, %d%% em até %s (n=%d).',
            $this->formatDays($median),
            $this->metrics->percentile(),
            $this->formatDays($p85),
            $count,
        );

        if ($percentile !== null) {
            $message .= sprintf(' Um apetite de %d dias cobre %d%% delas.', $appetiteDays, $percentile);
        }

        return [
            'sample_size' => $count,
            'has_history' => true,
            'median' => $median,
            'p85' => $p85,
            'percentile' => $percentile,
            'message' => $message,
        ];
    }

    /**
     * The apetite as every surface states it.
     */
    public function appetiteLabel(int $days): string
    {
        return $days === 1 ? '1 dia' : "{$days} dias";
    }

    private function formatDays(?float $days): string
    {
        if ($days === null) {
            return '—';
        }

        return $days == (int) $days
            ? ((int) $days).'d'
            : number_format($days, 1, ',', '').'d';
    }

    /**
     * Read a section's content as text, or null when there is nothing in it.
     *
     * Content reaches these columns from two kinds of field — plain markdown
     * from a textarea, and HTML from `<flux:editor>` (which is what the
     * Ideias modal writes into `description`). The pitch is markdown, so HTML
     * is flattened rather than pasted through; plain markdown is left exactly
     * as typed, since touching it would mangle lists and code fences.
     *
     * This is also the emptiness test the gate uses, so `<p></p>` from an
     * editor that was opened and closed counts as unwritten.
     */
    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/<[a-z!\/][^>]*>/i', $value) === 1) {
            $value = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', "\n", $value) ?? $value;
            $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $value = preg_replace('/[ \t]+$/m', '', $value) ?? $value;
            $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
        }

        $value = trim(str_replace("\u{A0}", ' ', $value));

        return $value === '' ? null : $value;
    }
}
