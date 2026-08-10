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
        $liveBets = $metrics->liveBets();
        $hunger = $metrics->intangibleStarvation();
        $clientWaits = $metrics->clientWaitRanking(30);
        $longestWait = $clientWaits->max('minutes') ?: 1;
    @endphp

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">Fluxo</flux:heading>
            <flux:text class="mt-1">O que o quadro promete, medido pelo que ele entregou.</flux:text>
        </div>

        <flux:button wire:click="openCutModal" variant="primary" icon="scissors" size="sm" data-test="cut-open">
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

    {{-- Fome de Intangível (issue #153): sempre visível, mesmo saciada — a
         classe que ninguém cobra só sobrevive se estiver sempre à vista. Âmbar
         ao estourar o limiar, e a despensa vazia não cala o alerta, só troca o
         remédio. --}}
    <div
        @class([
            'rounded-xl border p-6',
            'border-amber-500/40 bg-zinc-800/50' => $hunger['starving'],
            'border-zinc-700 bg-zinc-900/50' => ! $hunger['starving'],
        ])
        data-test="intangible-card"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <flux:icon name="sparkles" class="size-5 {{ $hunger['starving'] ? 'text-amber-400' : 'text-zinc-500' }}" />
                <flux:heading size="lg">Intangível</flux:heading>
            </div>

            @if ($hunger['starving'])
                <flux:badge size="sm" color="amber" data-test="intangible-starving">Fome de Intangível</flux:badge>
            @else
                <flux:badge size="sm" color="zinc" data-test="intangible-fed">Dentro do limiar</flux:badge>
            @endif
        </div>

        <div class="mt-3 flex flex-wrap items-baseline gap-3">
            <span class="text-3xl font-semibold {{ $hunger['starving'] ? 'text-amber-400' : 'text-zinc-300' }}">
                {{ $hunger['label'] }}
            </span>
            <flux:text class="text-sm">limiar de {{ $hunger['threshold'] }} dias</flux:text>
        </div>

        <flux:text class="mt-3 text-sm">
            O relógio conta desde a última entrada em Feito de um item Intangível — puxar sem concluir não zera.
            @if ($hunger['starving'] && $hunger['ready_count'] === 0)
                Não há nenhum Intangível em Pronto: o remédio é criar ou promover um, no Backlog ou nas Ideias.
            @elseif ($hunger['starving'])
                {{ $hunger['ready_count'] }} {{ $hunger['ready_count'] === 1 ? 'Intangível espera' : 'Intangíveis esperam' }}
                em Pronto — concluir um é o que mata a fome.
            @endif
        </flux:text>
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

    {{-- Apostas em andamento (issue #152): specs aprovadas e ainda não
         validadas, com o quanto do apetite já foi. Estouradas no topo; as sem
         apetite ficam no fim, listadas sem barra e sem alarme. --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-6" data-test="live-bets">
        <flux:heading size="lg">Apostas em andamento</flux:heading>
        <flux:text class="mt-1 text-sm">
            Épicos com a spec aprovada e ainda não validada. O consumo corre da aprovação até agora, na mesma
            janela da eficiência de fluxo, e avisa aos {{ $metrics->attentionPercent() }}% do apetite.
        </flux:text>

        @if ($liveBets->isEmpty())
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <flux:icon name="rectangle-stack" class="size-8 text-zinc-600" />
                <flux:text>Nenhuma aposta em andamento.</flux:text>
                <flux:text class="text-xs">
                    Uma spec aprovada vira aposta viva e aparece aqui até ser validada.
                </flux:text>
            </div>
        @else
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($liveBets as $row)
                    @php
                        $epic = $row['epic'];
                        $appetite = $row['consumption'];
                    @endphp

                    <div
                        wire:key="bet-{{ $epic->id }}"
                        @class([
                            'rounded-lg border bg-zinc-800 p-3',
                            'border-red-500' => $appetite['level'] === 'exceeded',
                            'border-amber-500' => $appetite['level'] === 'warning',
                            'border-zinc-700' => in_array($appetite['level'], ['ok', 'no_appetite'], true),
                        ])
                        data-test="bet-{{ $appetite['level'] }}"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <button
                                type="button"
                                wire:click="$dispatch('open-feature-modal', { featureId: {{ $epic->id }} })"
                                class="min-w-0 text-left"
                            >
                                <span class="block truncate text-sm font-medium text-zinc-200">{{ $epic->title }}</span>
                                <span class="mt-0.5 block text-xs text-zinc-500">
                                    {{ $epic->status->label() }}
                                    @if ($epic->project)
                                        · {{ $epic->project->emoji }} {{ $epic->project->name }}
                                    @endif
                                </span>
                            </button>

                            @if ($appetite['level'] === 'no_appetite')
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="text-xs text-zinc-500">sem apetite definido</span>
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        icon="scissors"
                                        :href="route('epic-shaping', $epic->id)"
                                        wire:navigate
                                        data-test="bet-shaping-{{ $epic->id }}"
                                    >Definir no shaping</flux:button>
                                </div>
                            @else
                                <div class="flex shrink-0 items-center gap-2">
                                    <flux:badge
                                        size="sm"
                                        :color="match ($appetite['level']) { 'exceeded' => 'red', 'warning' => 'amber', default => 'emerald' }"
                                    >{{ $appetite['label'] }}</flux:badge>

                                    @if ($appetite['level'] === 'exceeded')
                                        <flux:button
                                            variant="ghost"
                                            size="xs"
                                            icon="scissors"
                                            :href="route('epic-shaping', $epic->id)"
                                            wire:navigate
                                            data-test="bet-shaping-{{ $epic->id }}"
                                        >Revisar escopo</flux:button>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if ($appetite['level'] !== 'no_appetite')
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-700">
                                <div
                                    @class([
                                        'h-full rounded-full',
                                        'bg-red-500' => $appetite['level'] === 'exceeded',
                                        'bg-amber-500' => $appetite['level'] === 'warning',
                                        'bg-emerald-500' => $appetite['level'] === 'ok',
                                    ])
                                    style="width: {{ min(100, round($appetite['ratio'] * 100, 2)) }}%"
                                ></div>
                            </div>

                            @if ($appetite['level'] === 'exceeded')
                                <p class="mt-2 text-xs text-red-300" data-test="bet-banner-{{ $epic->id }}">
                                    {{ $appetite['headline'] }} — corte escopo ou mate a aposta.
                                </p>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Esperas por cliente (issue #146) --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-6" data-test="client-waits">
        <flux:heading size="lg">Esperas por cliente</flux:heading>
        <flux:text class="mt-1 text-sm">
            Quanto tempo cada cliente segurou o quadro nos últimos 30 dias, somando Aguardando aprovação e
            Aguardando validação. Espera interna (Esperando) fica de fora — ela não é do cliente. Esperas ainda
            abertas contam até agora.
        </flux:text>

        @if ($clientWaits->isEmpty())
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <flux:icon name="clock" class="size-8 text-zinc-600" />
                <flux:text>Nenhuma espera de cliente nos últimos 30 dias.</flux:text>
            </div>
        @else
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($clientWaits as $row)
                    @php
                        $client = $row['client'];
                        $days = $row['minutes'] / 1440;
                    @endphp

                    <div
                        class="rounded-lg border border-zinc-700 bg-zinc-800 p-3"
                        wire:key="client-wait-{{ $client->id }}"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $client->color }}"></span>
                                <span class="truncate text-sm font-medium text-zinc-200">{{ $client->name }}</span>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <flux:badge size="sm" color="zinc">
                                    {{ $row['items'] }} {{ $row['items'] === 1 ? 'item' : 'itens' }}
                                </flux:badge>
                                <span class="text-sm font-medium text-orange-300">
                                    {{ number_format($days, 1, ',', '.') }} dias
                                </span>
                            </div>
                        </div>
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-700">
                            <div
                                class="h-full rounded-full bg-orange-500/80"
                                style="width: {{ round(($row['minutes'] / $longestWait) * 100, 2) }}%"
                            ></div>
                        </div>
                    </div>
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
                    data-test="cut-reason"
                />
                <flux:error name="cutReason" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" data-test="cut-submit">
                    <span wire:loading.remove wire:target="saveCut">Cortar</span>
                    <span wire:loading wire:target="saveCut">Cortando...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
