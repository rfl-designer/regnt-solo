<?php

use App\Models\BaselineCut;
use App\Services\FlowMetricsService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Fluxo page (issue #145): what the board promises, what it measured
 * to get there, and what is currently breaking it.
 *
 * Everything on the page is read from {@see FlowMetricsService}. The page
 * computes nothing itself — the SLE quoted here, the one on the Kanban's
 * chip and the window the pull queue uses are all the same call, so they
 * cannot disagree.
 */
new class extends Component
{
    public bool $showCutModal = false;

    public string $cutReason = '';

    #[Computed]
    public function metrics(): FlowMetricsService
    {
        return app(FlowMetricsService::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, BaselineCut>
     */
    #[Computed]
    public function cuts(): \Illuminate\Database\Eloquent\Collection
    {
        return BaselineCut::query()->latestFirst()->get();
    }

    public function openCutModal(): void
    {
        $this->cutReason = '';
        $this->resetValidation();
        $this->showCutModal = true;
    }

    /**
     * Record a cut. The motivo is required in both places on purpose: the
     * rule here gives the user a field-level message, and the service
     * refuses a blank one regardless of origin.
     */
    public function saveCut(): void
    {
        $this->validate(
            ['cutReason' => 'required|string|max:500'],
            ['cutReason.required' => 'Descreva o motivo do corte.'],
        );

        $this->metrics->cut($this->cutReason);

        $this->showCutModal = false;
        $this->cutReason = '';
        unset($this->metrics, $this->cuts);

        Flux::toast(variant: 'success', heading: 'Baseline cortada', text: 'A SLE volta a contar a partir de agora.');
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 sm:p-6">
    @php
        $metrics = $this->metrics;
        $sleDays = $metrics->sleDays();
        $sampleSize = $metrics->sampleSize();
        $distribution = $metrics->distribution();
        $agingItems = $metrics->agingItems();
        $lastCut = $metrics->lastCut();
    @endphp

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">Fluxo</flux:heading>
            <flux:text class="mt-1">O que o quadro promete, medido pelo que ele entregou.</flux:text>
        </div>

        <flux:button wire:click="openCutModal" variant="primary" icon="scissors" size="sm">
            Cortar baseline
        </flux:button>
    </div>

    {{-- SLE card --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-6" data-test="sle-card">
        @if ($sleDays !== null)
            <div class="flex flex-wrap items-baseline gap-3">
                <span class="text-4xl font-semibold text-emerald-400">{{ $sleDays }} dias</span>
                <flux:text>
                    é o que {{ $metrics->percentile() }}% dos itens levam, no máximo, do Pronto ao Feito.
                </flux:text>
            </div>
            <flux:text class="mt-3 text-sm">
                Medido sobre {{ $sampleSize }} itens concluídos desde o último corte. A fila de puxar já usa
                esse número no lugar do prazo fixo, e os cards passam a avisar aos
                {{ $metrics->attentionPercent() }}% da SLE.
            </flux:text>
        @else
            <div class="flex flex-wrap items-baseline gap-3">
                <span class="text-4xl font-semibold text-zinc-400">amostra pequena</span>
                <flux:text>(n={{ $sampleSize }})</flux:text>
            </div>
            <flux:text class="mt-3 text-sm">
                A SLE precisa de {{ $metrics->minimumSample() }} itens concluídos desde o corte para virar uma
                promessa — faltam {{ max(0, $metrics->minimumSample() - $sampleSize) }}. Até lá a fila de puxar
                segue com a janela de risco configurada e nenhum card entra em alarme.
            </flux:text>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Distribution --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-6 lg:col-span-2" data-test="distribution">
            <flux:heading size="lg">Distribuição dos cycle times</flux:heading>
            <flux:text class="mt-1 text-sm">Quantos itens fecharam em cada quantidade de dias.</flux:text>

            @if ($distribution === [])
                <div class="flex flex-col items-center gap-2 py-12 text-center">
                    <flux:icon name="chart-bar" class="size-8 text-zinc-600" />
                    <flux:text>Nada concluído desde o último corte ainda.</flux:text>
                    <flux:text class="text-xs">
                        Conclua itens que passaram por Pronto e a distribuição aparece aqui.
                    </flux:text>
                </div>
            @else
                <flux:chart :value="$distribution" class="mt-4 aspect-[3/1]">
                    <flux:chart.svg>
                        <flux:chart.bar field="count" class="text-blue-500" />
                        <flux:chart.axis axis="x" field="days">
                            <flux:chart.axis.tick />
                            <flux:chart.axis.line />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                    </flux:chart.svg>
                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="days" />
                        <flux:chart.tooltip.value field="count" label="Itens" />
                    </flux:chart.tooltip>
                </flux:chart>
            @endif
        </div>

        {{-- Baseline cuts --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-6" data-test="cuts">
            <flux:heading size="lg">Cortes de baseline</flux:heading>
            <flux:text class="mt-1 text-sm">
                Cada corte reinicia a medição. O de cima é o que vale hoje.
            </flux:text>

            <div class="mt-4 flex flex-col gap-2">
                @forelse ($this->cuts as $cut)
                    <div
                        class="rounded-lg border bg-zinc-800 p-3 {{ $lastCut && $lastCut->is($cut) ? 'border-emerald-500/40' : 'border-zinc-700' }}"
                        wire:key="cut-{{ $cut->id }}"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm text-zinc-200">{{ $cut->reason }}</span>
                            @if ($lastCut && $lastCut->is($cut))
                                <flux:badge size="sm" color="emerald">Atual</flux:badge>
                            @endif
                        </div>
                        <span class="mt-1 block text-xs text-zinc-500">
                            {{ $cut->cut_at->format('d/m/Y H:i') }}
                        </span>
                    </div>
                @empty
                    <flux:text class="text-sm">Nenhum corte registrado.</flux:text>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Aging --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-6" data-test="aging">
        <flux:heading size="lg">Envelhecendo</flux:heading>
        <flux:text class="mt-1 text-sm">
            Itens em Pronto, Fazendo, Esperando ou Aguardando validação que já passaram dos
            {{ $metrics->attentionPercent() }}% da SLE.
        </flux:text>

        @if ($sleDays === null)
            <flux:text class="mt-4 text-sm" data-test="aging-no-baseline">
                Sem baseline utilizável não há alarme: nada é comparado contra uma promessa que ainda não existe.
            </flux:text>
        @elseif ($agingItems->isEmpty())
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <flux:icon name="check-circle" class="size-8 text-emerald-500/70" />
                <flux:text>Nada envelhecendo. Tudo dentro da SLE.</flux:text>
            </div>
        @else
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($agingItems as $row)
                    @php
                        $activity = $row['activity'];
                        $aging = $row['aging'];
                    @endphp

                    <button
                        type="button"
                        wire:key="aging-{{ $activity->id }}"
                        wire:click="$dispatch('open-task-modal', { taskId: {{ $activity->id }} })"
                        class="flex items-center justify-between gap-3 rounded-lg border bg-zinc-800 p-3 text-left transition hover:border-zinc-500 {{ $aging['level'] === 'breach' ? 'border-red-500' : 'border-amber-500' }}"
                    >
                        <div class="min-w-0">
                            <span class="block truncate text-sm font-medium text-zinc-200">{{ $activity->title }}</span>
                            <span class="mt-0.5 block text-xs text-zinc-500">
                                {{ $activity->status->label() }}
                                @if ($activity->project)
                                    · {{ $activity->project->emoji }} {{ $activity->project->name }}
                                @endif
                            </span>
                        </div>

                        <flux:badge size="sm" :color="$aging['level'] === 'breach' ? 'red' : 'amber'">
                            {{ $aging['tooltip'] }}
                        </flux:badge>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Cut modal --}}
    <flux:modal wire:model.self="showCutModal" class="md:w-[30rem]">
        <form wire:submit="saveCut" class="space-y-4">
            <div>
                <flux:heading size="lg">Cortar baseline</flux:heading>
                <flux:text class="mt-2">
                    A SLE passa a contar só o que for concluído a partir de agora. O motivo fica no histórico.
                </flux:text>
            </div>

            <flux:field>
                <flux:label>Motivo</flux:label>
                <flux:textarea
                    wire:model="cutReason"
                    rows="3"
                    placeholder="Ex: adoção do Fluxo Solo, saída de um cliente grande, mudança no jeito de trabalhar"
                />
                <flux:error name="cutReason" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveCut">Cortar</span>
                    <span wire:loading wire:target="saveCut">Cortando...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
