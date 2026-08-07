<?php

use App\Enums\ActivityStatus;
use App\Exceptions\DomainRefusal;
use App\Models\Activity;
use App\Models\MorningRitual;
use App\Services\FlowMetricsService;
use App\Services\PullQueueService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * O ritual matinal (issue #147) — o que substitui o Daily Planner.
 *
 * The Daily Planner asked the user to *build a list*: pick items, tick
 * them, carry the leftovers to tomorrow. That list was a second board,
 * kept by hand, that could disagree with the real one. The ritual asks
 * nothing to be built. It walks the board itself, one question per screen,
 * and every answer is a single click that moves a real card:
 *
 * 1. **Arquivar o Feito** — clear what is done, without moving it (see
 *    {@see Activity::archive()}: archiving is a timestamp, never a status,
 *    so the flow metrics don't notice it happened).
 * 2. **Revisar esperas** — the three waiting columns, each item resolvable
 *    forward in one click.
 * 3. **Confirmar o Fazendo** — is this still what you are doing? If not,
 *    back to Pronto.
 * 4. **Ver envelhecimento** — what is burning through the SLE. With no
 *    usable baseline this becomes a plain list by age, stated as such,
 *    with no alarm: an unpowered percentile must not raise one.
 * 5. **Puxar até encher o WIP** — the pull queue, in its order, with the
 *    WIP counter in view. Any card may be pulled; the queue states the
 *    order, the decision stays with the user.
 *
 * Then a final screen that records the ritual: a timestamp and optional
 * notes. The first conclusion of the day is the one that counts — reopening
 * the wizard later says "já concluído às HH:MM" instead of moving it.
 */
new class extends Component
{
    /**
     * The screen the wizard is on: 1..5 are the five steps, 6 is the
     * closing record. Kept in the URL so a half-done ritual survives a
     * refresh.
     */
    #[Url]
    public int $step = 1;

    public string $notes = '';

    /**
     * The last screen: not a step, the record of the ritual.
     */
    public const int FINAL_STEP = 6;

    public function mount(): void
    {
        $this->step = max(1, min(self::FINAL_STEP, $this->step));
        $this->notes = $this->ritual?->notes ?? '';
    }

    // ── O registro do dia ────────────────────────────────────────────────

    /**
     * Today's record, or null while the day hasn't produced one. The row is
     * only written when the ritual is concluded — merely opening the page
     * is not doing the ritual.
     */
    #[Computed]
    public function ritual(): ?MorningRitual
    {
        return MorningRitual::today();
    }

    #[Computed]
    public function alreadyCompleted(): bool
    {
        return $this->ritual?->isCompleted() ?? false;
    }

    #[Computed]
    public function completedAtLabel(): ?string
    {
        return $this->ritual?->completedAtLabel();
    }

    // ── Passo 1 — Arquivar o Feito ───────────────────────────────────────

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    #[Computed]
    public function doneToArchive(): \Illuminate\Database\Eloquent\Collection
    {
        return Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Done)
            ->notArchived()
            ->with(['project', 'client'])
            ->orderByDesc('completed_at')
            ->get();
    }

    public function archive(int $activityId): void
    {
        $activity = Activity::query()->findOrFail($activityId);
        $activity->archive();

        unset($this->doneToArchive);
        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Arquivada', text: $activity->title);
    }

    public function archiveAll(): void
    {
        $count = 0;

        foreach ($this->doneToArchive as $activity) {
            $activity->archive();
            $count++;
        }

        unset($this->doneToArchive);
        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Feito arquivado', text: $count.' '.($count === 1 ? 'item arquivado' : 'itens arquivados'));
    }

    // ── Passo 2 — Revisar esperas ────────────────────────────────────────

    /**
     * The three waiting columns, oldest wait first — the review only has a
     * point if what has been waiting longest is what you see first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    #[Computed]
    public function waitingItems(): \Illuminate\Database\Eloquent\Collection
    {
        $waitingValues = array_map(
            fn (ActivityStatus $status): string => $status->value,
            array_values(array_filter(ActivityStatus::cases(), fn (ActivityStatus $status): bool => $status->isWaiting())),
        );

        return Activity::query()
            ->leaf()
            ->whereIn('status', $waitingValues)
            ->with(['project', 'client'])
            ->orderBy('waiting_since')
            ->get();
    }

    /**
     * Where a wait goes when it is over — one click, one move forward:
     *
     * - Aguardando aprovação -> Pronto (o cliente disse sim; vira trabalho
     *   comprometido, e é aí que o relógio da SLE começa).
     * - Esperando -> Pronto (destravou; volta para a fila de puxar, não
     *   direto para Fazendo, que tem limite de WIP).
     * - Aguardando validação -> Feito (o cliente validou).
     */
    public function resolveWait(int $activityId): void
    {
        $activity = Activity::query()->findOrFail($activityId);

        if ($activity->status === null || ! $activity->status->isWaiting()) {
            return;
        }

        $target = $activity->status === ActivityStatus::AwaitingValidation
            ? ActivityStatus::Done
            : ActivityStatus::Todo;

        try {
            if ($target === ActivityStatus::Done) {
                $activity->markAsDone();
            } else {
                $activity->update(['status' => $target]);
            }
        } catch (DomainRefusal $e) {
            Flux::toast(variant: 'danger', heading: 'Não foi possível mover', text: $e->getMessage());

            return;
        }

        unset($this->waitingItems, $this->doneToArchive);
        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Espera resolvida', text: $activity->title.' → '.$target->label());
    }

    // ── Passo 3 — Confirmar o Fazendo ────────────────────────────────────

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    #[Computed]
    public function doingItems(): \Illuminate\Database\Eloquent\Collection
    {
        return Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Doing)
            ->with(['project', 'client'])
            ->ordered()
            ->get();
    }

    /**
     * "Isto não é o que estou fazendo": devolve para Pronto, liberando o
     * WIP para o que for puxado no passo 5.
     */
    public function sendBackToReady(int $activityId): void
    {
        $activity = Activity::query()->findOrFail($activityId);

        if ($activity->status !== ActivityStatus::Doing) {
            return;
        }

        try {
            $activity->update(['status' => ActivityStatus::Todo]);
        } catch (DomainRefusal $e) {
            Flux::toast(variant: 'danger', heading: 'Não foi possível mover', text: $e->getMessage());

            return;
        }

        unset($this->doingItems, $this->pullQueue);
        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Devolvida para Pronto', text: $activity->title);
    }

    // ── Passo 4 — Ver envelhecimento ─────────────────────────────────────

    #[Computed]
    public function flow(): FlowMetricsService
    {
        return app(FlowMetricsService::class);
    }

    /**
     * Whether the board has a promise to measure against at all.
     */
    #[Computed]
    public function hasUsableBaseline(): bool
    {
        return $this->flow->isUsable();
    }

    /**
     * What this step shows.
     *
     * With a usable baseline: exactly what {@see FlowMetricsService::agingItems()}
     * flags — everything at or past {@see FlowMetricsService::attentionPercent()}
     * of the SLE.
     *
     * Without one: the same columns listed by age, oldest first, and
     * nothing more. No threshold, no colour, no "atenção" — there is no Y
     * to be 80% of, and inventing an alarm off an unpowered percentile
     * teaches the user to ignore the real one later. The header says
     * "amostra pequena (n=X)" so the list is read for what it is.
     *
     * @return \Illuminate\Support\Collection<int, array{activity: Activity, days: float, level: string}>
     */
    #[Computed]
    public function agingRows(): \Illuminate\Support\Collection
    {
        if ($this->hasUsableBaseline) {
            return $this->flow->agingItems()->map(fn (array $row): array => [
                'activity' => $row['activity'],
                'days' => $row['aging']['days'],
                'level' => $row['aging']['level'],
            ]);
        }

        $candidates = Activity::query()
            ->leaf()
            ->whereIn('status', array_map(
                fn (ActivityStatus $status): string => $status->value,
                FlowMetricsService::agingStatuses(),
            ))
            ->with(['project', 'client'])
            ->get();

        $this->flow->warm($candidates);

        return $candidates
            ->map(fn (Activity $activity): array => [
                'activity' => $activity,
                // Falls back to the age of the record when the clock never
                // started (an item that never passed through Pronto): the
                // list is by age, and "no clock" is not "new".
                'days' => $this->flow->ageDays($activity) ?? $activity->created_at->floatDiffInDays(now()),
                'level' => 'ok',
            ])
            ->sortByDesc('days')
            ->values();
    }

    /**
     * The one-line honest statement above the list.
     */
    #[Computed]
    public function agingCaption(): string
    {
        if ($this->hasUsableBaseline) {
            return 'Itens a partir de '.$this->flow->attentionPercent().'% da '.$this->flow->label().'.';
        }

        return 'Sem baseline utilizável: '.$this->flow->label().'. Lista por idade, sem alarme.';
    }

    // ── Passo 5 — Puxar até encher o WIP ─────────────────────────────────

    /**
     * @return \Illuminate\Support\Collection<int, \App\Services\PullQueueEntry>
     */
    #[Computed]
    public function pullQueue(): \Illuminate\Support\Collection
    {
        return app(PullQueueService::class)->queue();
    }

    public function wipLimit(): int
    {
        return (int) config('soloboard.wip_limit_doing', 2);
    }

    public function doingWipCount(): int
    {
        return Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Doing)
            ->count();
    }

    public function wipIsFull(): bool
    {
        return $this->doingWipCount() >= $this->wipLimit();
    }

    /**
     * Pull one item into Fazendo.
     *
     * Any card in the queue may be pulled, not just the top one: the queue
     * states the order and the motivo, the choice is the user's. What is
     * *not* negotiable is the WIP limit — the domain guard refuses, and the
     * refusal is shown as it is rather than worked around here.
     */
    public function pullItem(int $activityId): void
    {
        $activity = Activity::query()->findOrFail($activityId);

        if ($activity->status !== ActivityStatus::Todo) {
            return;
        }

        try {
            $activity->update(['status' => ActivityStatus::Doing]);
        } catch (DomainRefusal $e) {
            Flux::toast(variant: 'danger', heading: 'Não foi possível puxar', text: $e->getMessage());

            return;
        }

        unset($this->pullQueue, $this->doingItems);
        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Puxada para Fazendo', text: $activity->title);
    }

    // ── Navegação e conclusão ────────────────────────────────────────────

    public function goToStep(int $step): void
    {
        $this->step = max(1, min(self::FINAL_STEP, $step));
    }

    public function nextStep(): void
    {
        $this->goToStep($this->step + 1);
    }

    public function previousStep(): void
    {
        $this->goToStep($this->step - 1);
    }

    /**
     * Conclude the ritual: stamp the day (once) and keep the notes.
     */
    public function completeRitual(): void
    {
        $ritual = MorningRitual::getOrCreateForDate(today());
        $ritual->complete($this->notes);

        unset($this->ritual, $this->alreadyCompleted, $this->completedAtLabel);

        $this->dispatch('ritual-completed');

        Flux::toast(
            variant: 'success',
            heading: 'Ritual concluído',
            text: 'Registrado às '.$ritual->completedAtLabel().'.',
        );
    }

    /**
     * The five steps plus the closing screen, as the header renders them.
     *
     * @return array<int, array{title: string, icon: string}>
     */
    public function steps(): array
    {
        return [
            1 => ['title' => 'Arquivar o Feito', 'icon' => 'archive-box'],
            2 => ['title' => 'Revisar esperas', 'icon' => 'pause-circle'],
            3 => ['title' => 'Confirmar o Fazendo', 'icon' => 'play-circle'],
            4 => ['title' => 'Ver envelhecimento', 'icon' => 'arrow-trending-up'],
            5 => ['title' => 'Puxar até encher o WIP', 'icon' => 'bars-arrow-down'],
            self::FINAL_STEP => ['title' => 'Registrar o ritual', 'icon' => 'check-circle'],
        ];
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-4 p-4 sm:p-6">
    @php $steps = $this->steps(); @endphp

    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <flux:icon name="sun" class="size-7 text-amber-400" />
            <flux:heading size="xl">Ritual matinal</flux:heading>
        </div>

        @if ($this->alreadyCompleted)
            <flux:badge color="emerald" icon="check-circle" data-test="ritual-already-completed">
                Já concluído às {{ $this->completedAtLabel }}
            </flux:badge>
        @endif
    </div>

    {{-- Trilha dos passos --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($steps as $number => $meta)
            <button
                type="button"
                wire:click="goToStep({{ $number }})"
                data-test="step-tab-{{ $number }}"
                class="flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs transition {{ $step === $number ? 'border-amber-500/40 bg-zinc-800/50 text-amber-300' : 'border-zinc-700 bg-zinc-900/50 text-zinc-400 hover:border-zinc-500' }}"
            >
                <flux:icon :name="$meta['icon']" class="size-4" />
                <span class="hidden sm:inline">{{ $meta['title'] }}</span>
                <span class="sm:hidden">{{ $number }}</span>
            </button>
        @endforeach
    </div>

    <div class="flex flex-1 flex-col gap-3 rounded-xl border border-zinc-700 bg-zinc-900/50 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">{{ $steps[$step]['title'] }}</flux:heading>

            @if ($step === 5)
                <flux:tooltip content="Limite de {{ $this->wipLimit() }} itens em Fazendo — só uma Emergência fura o limite.">
                    <flux:badge size="sm" :color="$this->wipIsFull() ? 'red' : 'amber'" data-test="ritual-wip">
                        {{ $this->doingWipCount() }}/{{ $this->wipLimit() }} em Fazendo
                    </flux:badge>
                </flux:tooltip>
            @endif
        </div>

        {{-- Passo 1 — Arquivar o Feito --}}
        @if ($step === 1)
            <flux:text class="text-sm text-zinc-400">
                Arquivar é só um carimbo: o item continua Feito e as métricas de ciclo não mudam.
            </flux:text>

            @if ($this->doneToArchive->isNotEmpty())
                <div>
                    <flux:button wire:click="archiveAll" variant="primary" size="sm" icon="archive-box" data-test="archive-all">
                        Arquivar tudo ({{ $this->doneToArchive->count() }})
                    </flux:button>
                </div>

                <ul class="divide-y divide-zinc-700/50 rounded-lg border border-zinc-700 bg-zinc-800/40">
                    @foreach ($this->doneToArchive as $activity)
                        <li wire:key="archive-{{ $activity->id }}" class="flex items-center gap-3 px-4 py-3">
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="truncate text-sm font-medium text-zinc-200">{{ $activity->title }}</span>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($activity->project)
                                        <div class="flex items-center gap-1 border-l-2 pl-1.5" style="border-color: {{ $activity->project->color }}">
                                            <span class="text-xs">{{ $activity->project->emoji }}</span>
                                            <span class="truncate text-xs text-zinc-400">{{ $activity->project->name }}</span>
                                        </div>
                                    @endif
                                    @if ($activity->completed_at)
                                        <flux:badge size="sm" color="emerald" icon="check-circle">
                                            {{ $activity->completed_at->translatedFormat('d/m') }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </div>

                            <flux:button
                                wire:click="archive({{ $activity->id }})"
                                size="sm"
                                variant="ghost"
                                icon="archive-box"
                            >
                                Arquivar
                            </flux:button>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="py-10 text-center">
                    <flux:icon name="archive-box" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Nada em Feito para arquivar.</flux:text>
                </div>
            @endif
        @endif

        {{-- Passo 2 — Revisar esperas --}}
        @if ($step === 2)
            <flux:text class="text-sm text-zinc-400">
                As três colunas de espera, da mais antiga para a mais recente.
            </flux:text>

            @if ($this->waitingItems->isNotEmpty())
                <ul class="divide-y divide-zinc-700/50 rounded-lg border border-zinc-700 bg-zinc-800/40">
                    @foreach ($this->waitingItems as $activity)
                        <li wire:key="waiting-{{ $activity->id }}" class="flex items-center gap-3 px-4 py-3">
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="truncate text-sm font-medium text-zinc-200">{{ $activity->title }}</span>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <flux:badge size="sm" color="{{ $activity->status->color() }}" icon="{{ $activity->status->icon() }}">
                                        {{ $activity->status->label() }}
                                    </flux:badge>
                                    <flux:badge size="sm" color="zinc">
                                        ⏳ {{ $activity->waiting_for }} · há {{ $activity->waitingDays() }} {{ $activity->waitingDays() === 1 ? 'dia' : 'dias' }}
                                    </flux:badge>
                                </div>
                            </div>

                            <flux:button
                                wire:click="resolveWait({{ $activity->id }})"
                                size="sm"
                                variant="ghost"
                                icon="arrow-right"
                            >
                                {{ $activity->status === \App\Enums\ActivityStatus::AwaitingValidation ? 'Validado' : 'Destravou' }}
                            </flux:button>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="py-10 text-center">
                    <flux:icon name="pause-circle" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Nada esperando.</flux:text>
                </div>
            @endif
        @endif

        {{-- Passo 3 — Confirmar o Fazendo --}}
        @if ($step === 3)
            <flux:text class="text-sm text-zinc-400">
                Ainda é isto que você está fazendo? O que não for volta para Pronto.
            </flux:text>

            @if ($this->doingItems->isNotEmpty())
                <ul class="divide-y divide-zinc-700/50 rounded-lg border border-zinc-700 bg-zinc-800/40">
                    @foreach ($this->doingItems as $activity)
                        <li wire:key="doing-{{ $activity->id }}" class="flex items-center gap-3 px-4 py-3">
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="truncate text-sm font-medium text-zinc-200">{{ $activity->title }}</span>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($activity->service_class)
                                        <flux:badge size="sm" color="{{ $activity->service_class->color() }}" icon="{{ $activity->service_class->icon() }}">
                                            {{ $activity->service_class->label() }}
                                        </flux:badge>
                                    @endif
                                    @if ($activity->project)
                                        <span class="truncate text-xs text-zinc-400">{{ $activity->project->emoji }} {{ $activity->project->name }}</span>
                                    @endif
                                </div>
                            </div>

                            <flux:button
                                wire:click="sendBackToReady({{ $activity->id }})"
                                size="sm"
                                variant="ghost"
                                icon="arrow-uturn-left"
                            >
                                Devolver p/ Pronto
                            </flux:button>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="py-10 text-center">
                    <flux:icon name="play-circle" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Nada em Fazendo.</flux:text>
                </div>
            @endif
        @endif

        {{-- Passo 4 — Ver envelhecimento --}}
        @if ($step === 4)
            <flux:text class="text-sm text-zinc-400" data-test="aging-caption">{{ $this->agingCaption }}</flux:text>

            @if ($this->agingRows->isNotEmpty())
                <ul class="divide-y divide-zinc-700/50 rounded-lg border border-zinc-700 bg-zinc-800/40">
                    @foreach ($this->agingRows as $row)
                        @php
                            $activity = $row['activity'];
                            $badgeColor = match ($row['level']) {
                                'breach' => 'red',
                                'attention' => 'amber',
                                default => 'zinc',
                            };
                        @endphp

                        <li wire:key="aging-{{ $activity->id }}" class="flex items-center gap-3 px-4 py-3">
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="truncate text-sm font-medium text-zinc-200">{{ $activity->title }}</span>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <flux:badge size="sm" color="{{ $activity->status->color() }}" icon="{{ $activity->status->icon() }}">
                                        {{ $activity->status->label() }}
                                    </flux:badge>
                                    @if ($activity->project)
                                        <span class="truncate text-xs text-zinc-400">{{ $activity->project->emoji }} {{ $activity->project->name }}</span>
                                    @endif
                                </div>
                            </div>

                            <flux:badge size="sm" color="{{ $badgeColor }}">
                                {{ number_format($row['days'], 1, ',', '') }} dias
                            </flux:badge>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="py-10 text-center">
                    <flux:icon name="arrow-trending-up" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Nada envelhecendo.</flux:text>
                </div>
            @endif
        @endif

        {{-- Passo 5 — Puxar até encher o WIP --}}
        @if ($step === 5)
            <flux:text class="text-sm text-zinc-400">
                A fila diz a ordem e o motivo. Puxar é decisão sua — qualquer card pode ser puxado.
            </flux:text>

            @if ($this->pullQueue->isNotEmpty())
                <ul class="divide-y divide-zinc-700/50 rounded-lg border border-zinc-700 bg-zinc-800/40">
                    @foreach ($this->pullQueue as $entry)
                        <li wire:key="pull-{{ $entry->activity->id }}" class="flex items-center gap-3 px-4 py-3">
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="truncate text-sm font-medium text-zinc-200">{{ $entry->activity->title }}</span>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <flux:badge size="sm" color="zinc">{{ $entry->reason->label() }}</flux:badge>
                                    <span class="truncate text-xs text-zinc-500">{{ $entry->positionReason() }}</span>
                                </div>
                            </div>

                            <flux:button
                                wire:click="pullItem({{ $entry->activity->id }})"
                                size="sm"
                                variant="primary"
                                icon="arrow-right-circle"
                            >
                                Puxar
                            </flux:button>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="py-10 text-center">
                    <flux:icon name="bars-arrow-down" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Nada em Pronto para puxar.</flux:text>
                </div>
            @endif
        @endif

        {{-- Tela final — Registrar o ritual --}}
        @if ($step === 6)
            @if ($this->alreadyCompleted)
                <flux:text class="text-sm text-zinc-400">
                    O ritual de hoje já foi concluído às {{ $this->completedAtLabel }}. As notas continuam editáveis.
                </flux:text>
            @else
                <flux:text class="text-sm text-zinc-400">
                    Fim do ritual. As notas são opcionais.
                </flux:text>
            @endif

            <flux:textarea
                wire:model="notes"
                label="Notas do dia"
                placeholder="O que ficou na cabeça hoje..."
                rows="auto"
            />

            <div>
                <flux:button
                    wire:click="completeRitual"
                    wire:loading.attr="disabled"
                    wire:target="completeRitual"
                    variant="primary"
                    icon="check-circle"
                    data-test="complete-ritual"
                >
                    {{ $this->alreadyCompleted ? 'Salvar notas' : 'Concluir ritual' }}
                </flux:button>
            </div>
        @endif
    </div>

    {{-- Navegação --}}
    <div class="flex items-center justify-between">
        <flux:button
            wire:click="previousStep"
            variant="ghost"
            icon="chevron-left"
            size="sm"
            :disabled="$step === 1"
        >
            Voltar
        </flux:button>

        <flux:text class="text-xs text-zinc-500">
            Passo {{ min($step, 5) }} de 5{{ $step === 6 ? ' · registro' : '' }}
        </flux:text>

        <flux:button
            wire:click="nextStep"
            variant="subtle"
            icon-trailing="chevron-right"
            size="sm"
            :disabled="$step === 6"
        >
            {{ $step === 5 ? 'Registrar' : 'Próximo' }}
        </flux:button>
    </div>
</div>
