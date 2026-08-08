<?php

use App\Enums\ActivityType;
use App\Exceptions\ShapingIncompleteException;
use App\Models\Activity;
use App\Models\Project;
use App\Services\ShapingService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dar forma a uma Ideia (issue #148).
 *
 * Five sections stacked on one page, navigable in any order. There is no
 * wizard and no step validation on the way through, because shaping is not a
 * sequence — you write a no-go, it changes the esboço, you come back and cut
 * the apetite. The only gate in the whole flow is the promotion itself, and
 * it lives in {@see ShapingService}, not here.
 *
 * Everything autosaves. "Talvez mais adiante" is therefore just a link back
 * to Ideias: there is nothing to discard and nothing to confirm, which is the
 * point — leaving in the middle has to cost nothing or partial shaping stops
 * happening.
 */
new class extends Component
{
    public int $draftId;

    public string $dor = '';

    public ?int $appetiteDays = null;

    /** Whether the "outro" chip is the one selected — kept in the component
     *  because a custom 7 and the 7 chip are indistinguishable from the value
     *  alone, and the user's choice of input shouldn't flicker on save. */
    public bool $customAppetite = false;

    public ?int $customAppetiteDays = null;

    public string $esboco = '';

    public string $rabbitHoles = '';

    public string $noGos = '';

    public ?int $projectId = null;

    public function mount(int $draft): void
    {
        $activity = Activity::query()->drafts()->findOrFail($draft);

        $this->draftId = $activity->id;
        $this->dor = $activity->description ?? '';
        $this->esboco = $activity->spec ?? '';
        $this->rabbitHoles = $activity->rabbit_holes ?? '';
        $this->noGos = $activity->no_gos ?? '';
        $this->appetiteDays = $activity->appetite_days;
        $this->projectId = $activity->project_id;

        if ($this->appetiteDays !== null && ! in_array($this->appetiteDays, ShapingService::APPETITE_CHIPS, true)) {
            $this->customAppetite = true;
            $this->customAppetiteDays = $this->appetiteDays;
        }
    }

    #[Computed]
    public function draft(): Activity
    {
        return Activity::query()->findOrFail($this->draftId);
    }

    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::query()->orderBy('name')->get();
    }

    #[Computed]
    public function sections(): array
    {
        return app(ShapingService::class)->sections($this->draft);
    }

    #[Computed]
    public function progress(): int
    {
        return app(ShapingService::class)->progress($this->draft);
    }

    /**
     * The aside. Read on every render because the chosen apetite is half of
     * what it says.
     */
    #[Computed]
    public function history(): array
    {
        return app(ShapingService::class)->appetiteHistory($this->appetiteDays);
    }

    #[Computed]
    public function missing(): array
    {
        return app(ShapingService::class)->missingForPromotion($this->draft, $this->projectId);
    }

    #[Computed]
    public function pitch(): string
    {
        return app(ShapingService::class)->pitch($this->draft);
    }

    /**
     * Autosave. Every bound field lands here, so no section needs a save
     * button and none of them can disagree about when the work is safe.
     */
    public function updated(string $property): void
    {
        if ($property === 'customAppetiteDays') {
            $this->appetiteDays = $this->customAppetiteDays !== null && $this->customAppetiteDays > 0
                ? $this->customAppetiteDays
                : null;
        }

        $this->persist();
    }

    public function chooseAppetite(int $days): void
    {
        $this->customAppetite = false;
        $this->customAppetiteDays = null;
        $this->appetiteDays = $days;

        $this->persist();
    }

    public function chooseCustomAppetite(): void
    {
        $this->customAppetite = true;
        $this->appetiteDays = $this->customAppetiteDays;

        $this->persist();
    }

    private function persist(): void
    {
        $this->draft->update([
            'description' => $this->dor !== '' ? $this->dor : null,
            'spec' => $this->esboco !== '' ? $this->esboco : null,
            'rabbit_holes' => $this->rabbitHoles !== '' ? $this->rabbitHoles : null,
            'no_gos' => $this->noGos !== '' ? $this->noGos : null,
            'appetite_days' => $this->appetiteDays,
            'project_id' => $this->projectId,
        ]);

        unset($this->draft, $this->sections, $this->progress, $this->missing, $this->history, $this->pitch);
    }

    /**
     * Bet on it. The refusal and the mutation both belong to
     * {@see ShapingService} — this only decides where to go afterwards.
     */
    public function promote()
    {
        $this->persist();

        try {
            $epic = app(ShapingService::class)->promote($this->draft, $this->projectId);
        } catch (ShapingIncompleteException $e) {
            Flux::toast(variant: 'warning', heading: 'Ainda não dá para promover', text: $e->getMessage());

            return null;
        }

        Flux::toast(variant: 'success', heading: 'Épico criado', text: "{$epic->title} nasceu em Backlog.");

        return $this->redirect(route('kanban'), navigate: true);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col">
    <div class="flex flex-1 flex-col gap-6 overflow-y-auto p-6 pb-28">
        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <flux:icon name="light-bulb" class="size-6 text-amber-400" />
                    <flux:heading size="xl" class="truncate">{{ $this->draft->title }}</flux:heading>
                </div>
                <flux:text class="mt-1 text-sm text-zinc-400">
                    Dar forma antes de apostar: dor, apetite e esboço bastam para virar Épico.
                </flux:text>
            </div>

            <div
                class="shrink-0"
                x-data="{ copied: false }"
                x-on:click="navigator.clipboard.writeText(@js($this->pitch)); copied = true; setTimeout(() => copied = false, 1500)"
            >
                <flux:button variant="ghost" size="sm" icon="clipboard-document" data-test="copy-pitch">
                    <span x-show="!copied">Copiar pitch</span>
                    <span x-show="copied" x-cloak>Copiado!</span>
                </flux:button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-4 lg:col-span-2">
                {{-- 1. Dor --}}
                <section id="secao-dor" class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-5">
                    <flux:heading size="sm" class="mb-1">1 · Dor</flux:heading>
                    <flux:text class="mb-3 text-xs text-zinc-500">O problema real de quem sofre com isso hoje.</flux:text>
                    <flux:textarea
                        wire:model.blur="dor"
                        rows="4"
                        placeholder="Quem sente essa dor, e o que ela custa hoje?"
                        data-test="field-dor"
                    />
                </section>

                {{-- 2. Apetite --}}
                <section id="secao-apetite" class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-5">
                    <flux:heading size="sm" class="mb-1">2 · Apetite</flux:heading>
                    <flux:text class="mb-3 text-xs text-zinc-500">Quanto tempo corrido isso vale — orçamento, não estimativa.</flux:text>

                    <div class="flex flex-wrap items-center gap-2">
                        @foreach (App\Services\ShapingService::APPETITE_CHIPS as $chip)
                            <button
                                type="button"
                                wire:key="chip-{{ $chip }}"
                                wire:click="chooseAppetite({{ $chip }})"
                                data-test="appetite-chip-{{ $chip }}"
                                @class([
                                    'rounded-lg border px-3 py-1.5 text-sm transition',
                                    'border-violet-500/60 bg-violet-500/10 text-violet-200' => ! $customAppetite && $appetiteDays === $chip,
                                    'border-zinc-700 bg-zinc-800 text-zinc-300 hover:border-zinc-500' => $customAppetite || $appetiteDays !== $chip,
                                ])
                            >{{ $chip }} dias</button>
                        @endforeach

                        <button
                            type="button"
                            wire:click="chooseCustomAppetite"
                            data-test="appetite-chip-other"
                            @class([
                                'rounded-lg border px-3 py-1.5 text-sm transition',
                                'border-violet-500/60 bg-violet-500/10 text-violet-200' => $customAppetite,
                                'border-zinc-700 bg-zinc-800 text-zinc-300 hover:border-zinc-500' => ! $customAppetite,
                            ])
                        >Outro</button>

                        @if ($customAppetite)
                            <flux:input
                                type="number"
                                min="1"
                                size="sm"
                                class="w-28"
                                wire:model.blur="customAppetiteDays"
                                placeholder="dias"
                                data-test="appetite-custom"
                            />
                        @endif
                    </div>

                    {{-- Aside "Seu histórico": espelho, nunca porteiro. --}}
                    <div
                        class="mt-4 rounded-lg border border-blue-500/20 bg-zinc-800/50 p-3"
                        data-test="appetite-history"
                    >
                        <div class="flex items-center gap-2">
                            <flux:icon name="chart-bar" class="size-4 text-blue-400" />
                            <span class="text-xs font-medium text-zinc-300">Seu histórico</span>
                        </div>
                        <p class="mt-1 text-xs text-zinc-400">{{ $this->history['message'] }}</p>
                        <p class="mt-1 text-[0.65rem] text-zinc-500">Comparação, não trava — o apetite é seu.</p>
                    </div>
                </section>

                {{-- 3. Esboço --}}
                <section id="secao-esboco" class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-5">
                    <flux:heading size="sm" class="mb-1">3 · Esboço</flux:heading>
                    <flux:text class="mb-3 text-xs text-zinc-500">A forma da solução, grosseira o bastante para caber no apetite.</flux:text>
                    <flux:editor wire:model.blur="esboco" placeholder="Como isso funciona, por cima..." />
                </section>

                {{-- 4. Rabbit holes --}}
                <section id="secao-rabbit-holes" class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-5">
                    <flux:heading size="sm" class="mb-1">4 · Rabbit holes</flux:heading>
                    <flux:text class="mb-3 text-xs text-zinc-500">Onde isso pode virar buraco sem fundo (opcional).</flux:text>
                    <flux:textarea
                        wire:model.blur="rabbitHoles"
                        rows="3"
                        placeholder="O que pode explodir de escopo?"
                        data-test="field-rabbit-holes"
                    />
                </section>

                {{-- 5. No-gos --}}
                <section id="secao-no-gos" class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-5">
                    <flux:heading size="sm" class="mb-1">5 · No-gos</flux:heading>
                    <flux:text class="mb-3 text-xs text-zinc-500">O que fica explicitamente de fora (opcional).</flux:text>
                    <flux:textarea
                        wire:model.blur="noGos"
                        rows="3"
                        placeholder="O que esta aposta não vai fazer?"
                        data-test="field-no-gos"
                    />
                </section>
            </div>

            {{-- Navegação livre entre as seções --}}
            <aside class="lg:col-span-1">
                <div class="sticky top-6 rounded-xl border border-zinc-700 bg-zinc-900/50 p-4">
                    <flux:heading size="sm" class="mb-3">Seções</flux:heading>
                    <nav class="flex flex-col gap-1">
                        @foreach ($this->sections as $index => $section)
                            <a
                                href="#secao-{{ str_replace('_', '-', $section['key']) }}"
                                wire:key="nav-{{ $section['key'] }}"
                                class="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm text-zinc-300 transition hover:bg-zinc-800"
                            >
                                <span>{{ $index + 1 }} · {{ $section['label'] }}</span>
                                @if ($section['filled'])
                                    <flux:icon name="check-circle" class="size-4 text-emerald-400" />
                                @elseif ($section['required'])
                                    <span class="text-[0.65rem] text-amber-400">obrigatória</span>
                                @endif
                            </a>
                        @endforeach
                    </nav>
                </div>
            </aside>
        </div>
    </div>

    {{-- Rodapé fixo: pips de progresso, projeto e as duas saídas. --}}
    <div class="sticky bottom-0 border-t border-zinc-700 bg-zinc-900/95 px-6 py-3 backdrop-blur">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-1.5" data-test="progress-pips">
                    @foreach ($this->sections as $section)
                        <span
                            wire:key="pip-{{ $section['key'] }}"
                            @class([
                                'size-2 rounded-full',
                                'bg-violet-400' => $section['filled'],
                                'bg-zinc-700' => ! $section['filled'],
                            ])
                            title="{{ $section['label'] }}"
                        ></span>
                    @endforeach
                </div>
                <span class="text-xs text-zinc-400">{{ $this->progress }}/5</span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:select wire:model.live="projectId" size="sm" class="max-w-56" data-test="project-select">
                    <option value="">Escolha um projeto</option>
                    @foreach ($this->projects as $project)
                        <option value="{{ $project->id }}">
                            {{ $project->emoji }} {{ $project->name }}{{ $project->client_id === null ? ' · interno' : '' }}
                        </option>
                    @endforeach
                </flux:select>

                <flux:button
                    variant="ghost"
                    size="sm"
                    :href="route('ideas')"
                    wire:navigate
                    data-test="maybe-later"
                >Talvez mais adiante</flux:button>

                <flux:button
                    variant="primary"
                    size="sm"
                    icon="rocket-launch"
                    class="bg-violet-600 hover:bg-violet-500"
                    wire:click="promote"
                    data-test="promote"
                >Promover a Épico</flux:button>
            </div>
        </div>

        @if ($this->missing !== [])
            <p class="mt-2 text-xs text-zinc-500" data-test="promote-hint">
                Falta para promover: {{ implode(', ', $this->missing) }}.
            </p>
        @endif
    </div>
</div>
