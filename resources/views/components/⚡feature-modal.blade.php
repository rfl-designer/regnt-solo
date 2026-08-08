<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Exceptions\WaitingRequiresWaitingForException;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\FlowMetricsService;
use App\Services\ShapingService;
use App\Support\Markdown;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?int $featureId = null;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string|max:65535')]
    public string $spec = '';

    public ?int $projectId = null;

    public string $priority = 'medium';

    public ?string $dueDate = null;

    public bool $showDeleteConfirm = false;

    public bool $editingSpec = false;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::active()->orderBy('name')->get();
    }

    #[Computed]
    public function feature(): ?Activity
    {
        if (! $this->featureId) {
            return null;
        }

        return Activity::with(['project.client', 'client', 'children.timeEntries', 'timeEntries', 'statusChanges'])->find($this->featureId);
    }

    /**
     * The pitch of the bet this Épico is (issue #148).
     *
     * Rendered by {@see ShapingService} from the same five sections the
     * shaping page writes, so the markdown copied here and the markdown
     * copied there are byte-for-byte the same document — the Épico *is* the
     * Ideia, promoted in place, and there is no second source to drift from.
     */
    #[Computed]
    public function pitch(): string
    {
        $feature = $this->feature;

        return $feature === null ? '' : app(ShapingService::class)->pitch($feature);
    }

    /**
     * The Spec's lifecycle, ready to render (issue #146).
     *
     * The four dates are read straight off the Épico's accessors, which
     * derive them from the status history — the modal stamps nothing. The
     * "current" step is the one the Spec is actually sitting on: a Spec
     * sent again after an approval is back on Enviada, which is exactly
     * what {@see Activity::isSpecPending()} answers.
     *
     * @return list<array{key: string, label: string, icon: string, at: ?\Carbon\CarbonInterface, done: bool, current: bool}>
     */
    #[Computed]
    public function specTimeline(): array
    {
        $feature = $this->feature;

        if (! $feature) {
            return [];
        }

        $steps = [
            ['key' => 'enviada', 'label' => 'Enviada', 'icon' => 'paper-airplane', 'at' => $feature->spec_enviada],
            ['key' => 'aprovada', 'label' => 'Aprovada', 'icon' => 'hand-thumb-up', 'at' => $feature->spec_aprovada],
            ['key' => 'entregue', 'label' => 'Entregue', 'icon' => 'truck', 'at' => $feature->spec_entregue],
            ['key' => 'validada', 'label' => 'Validada', 'icon' => 'check-badge', 'at' => $feature->spec_validada],
        ];

        // The lit step comes from Activity::specStage(), which walks the
        // history in order. Inferring it from "which timestamps exist" would
        // keep Validada lit on an Épico that was validated, reopened and
        // delivered again — the date stays true, the stage does not.
        $currentIndex = array_search($feature->specStage(), Activity::SPEC_STAGES, true);
        $currentIndex = $currentIndex === false ? -1 : $currentIndex;

        return collect($steps)
            ->map(fn (array $step, int $index): array => [
                ...$step,
                // Only the steps already left behind are ticked. A date that
                // sits *ahead* of the current stage is history, not progress.
                'done' => $step['at'] !== null && $index < $currentIndex,
                'current' => $index === $currentIndex,
            ])
            ->all();
    }

    /**
     * The flow efficiency of this Spec, or null while it has no approval to
     * measure from.
     *
     * @return array{window_start: \Carbon\CarbonInterface, window_end: \Carbon\CarbonInterface, open: bool, window_minutes: float, touch_minutes: float, ratio: float, percent: int}|null
     */
    #[Computed]
    public function specEfficiency(): ?array
    {
        return $this->feature
            ? app(FlowMetricsService::class)->specEfficiency($this->feature)
            : null;
    }

    /**
     * The two shortcuts on the timeline are sugar and nothing else: they
     * set the status, and the status change *is* the lifecycle event. There
     * is no second mechanism recording a "sent at" — moving the Épico from
     * the Kanban, from MCP or from here all produce the same history.
     */
    public function sendForApproval(): void
    {
        $this->moveTo(ActivityStatus::AwaitingApproval, 'Spec enviada para aprovação');
    }

    public function deliverForValidation(): void
    {
        $this->moveTo(ActivityStatus::AwaitingValidation, 'Entregue para validação');
    }

    private function moveTo(ActivityStatus $status, string $heading): void
    {
        if (! $this->feature) {
            return;
        }

        try {
            $this->feature->update(['status' => $status]);
        } catch (WaitingRequiresWaitingForException $e) {
            // Both moves are client-side waits, so "esperando quem" is
            // resolved from the effective client — an Épico with no client
            // has nobody to wait on, and saying so beats a raw exception.
            Flux::toast(
                variant: 'danger',
                heading: 'Sem cliente para esperar',
                text: 'Vincule o épico a um projeto ou cliente antes de mandar para '.$status->label().'.',
            );

            return;
        }

        unset($this->feature, $this->specTimeline, $this->specEfficiency, $this->pitch);

        Flux::toast(variant: 'success', heading: $heading, text: $this->title);
        $this->dispatch('feature-updated');
    }

    /**
     * Format a duration in minutes as "3d 4h" / "5h" / "40m".
     */
    public function formatSpan(float $minutes): string
    {
        $days = intdiv((int) $minutes, 1440);
        $hours = intdiv((int) $minutes % 1440, 60);

        if ($days > 0) {
            return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
        }

        return $this->formatDuration($minutes);
    }

    #[On('open-feature-modal')]
    public function open(?int $featureId = null): void
    {
        $this->reset();
        $this->featureId = $featureId;

        if ($featureId) {
            $feature = Activity::find($featureId);

            if ($feature) {
                $this->title = $feature->title;
                $this->spec = $feature->spec ?? '';
                $this->projectId = $feature->project_id;
                $this->priority = $feature->priority?->value ?? 'medium';
                $this->dueDate = $feature->due_date?->format('Y-m-d');

                $this->editingSpec = $feature->status === ActivityStatus::Done
                    ? false
                    : empty($this->spec);
            }
        } else {
            $this->editingSpec = true;
        }

        unset($this->feature, $this->pitch);
        Flux::modal('feature-modal')->show();
    }

    public function toggleSpec(): void
    {
        if ($this->editingSpec && $this->featureId) {
            $this->save();
        }

        $this->editingSpec = ! $this->editingSpec;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'title' => $this->title,
            'spec' => $this->spec ?: null,
            'project_id' => $this->projectId ?: null,
            'priority' => ActivityPriority::from($this->priority),
            'due_date' => $this->dueDate ?: null,
        ];

        if ($this->featureId) {
            $feature = Activity::findOrFail($this->featureId);
            $feature->update($data);

            Flux::toast(variant: 'success', heading: 'Feature atualizada', text: $feature->title);
            $this->dispatch('feature-updated');
        } else {
            $feature = Activity::create(array_merge($data, ['type' => ActivityType::Epic, 'status' => ActivityStatus::Backlog]));

            Flux::toast(variant: 'success', heading: 'Feature criada', text: $feature->title);
            $this->dispatch('feature-created');

            $this->featureId = $feature->id;
        }

        unset($this->feature, $this->pitch);
    }

    public function startTimer(): void
    {
        if (! $this->feature) {
            return;
        }

        $this->feature->startTimer();

        Flux::toast(variant: 'success', heading: 'Timer iniciado', text: $this->feature->title);
        $this->dispatch('timer-started');
        $this->dispatch('feature-updated');

        unset($this->feature, $this->pitch);
    }

    public function stopTimer(): void
    {
        if (! $this->feature) {
            return;
        }

        $runningEntry = $this->feature->timeEntries()->running()->first();

        if ($runningEntry) {
            $this->dispatch('open-timer-notes', entryId: $runningEntry->id);
        }
    }

    public function confirmDelete(): void
    {
        $this->showDeleteConfirm = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteConfirm = false;
    }

    public function delete(): void
    {
        if (! $this->featureId) {
            return;
        }

        $feature = Activity::findOrFail($this->featureId);
        $title = $feature->title;

        // Unlink children from feature (don't delete them)
        $feature->children()->update(['parent_id' => null]);

        // Delete time entries and feature
        $feature->timeEntries()->delete();
        $feature->delete();

        Flux::toast(variant: 'success', heading: 'Feature excluída', text: $title);
        $this->dispatch('feature-updated');

        Flux::modal('feature-modal')->close();
        $this->reset();
    }

    /**
     * Format minutes as human-readable duration.
     */
    public function formatDuration(float $minutes): string
    {
        if ($minutes === 0.0) {
            return '0m';
        }

        $hours = intdiv((int) $minutes, 60);
        $mins = (int) $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}m";
        }

        if ($hours > 0) {
            return "{$hours}h";
        }

        return "{$mins}m";
    }
}

?>

<flux:modal name="feature-modal" class="w-full max-w-3xl space-y-6" variant="flyout">
    <div>
        <div class="flex items-center gap-2">
            <flux:heading size="lg">
                {{ $featureId ? 'Editar Feature' : 'Nova Feature' }}
            </flux:heading>
            @if ($featureId)
                <span
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText('#F-{{ $featureId }}'); copied = true; setTimeout(() => copied = false, 1500)"
                    class="cursor-pointer text-sm font-mono transition-colors duration-200"
                    :class="copied ? 'text-emerald-400' : 'text-zinc-500 hover:text-zinc-300'"
                    title="Copiar ID"
                >
                    <span x-show="!copied">#F-{{ $featureId }}</span>
                    <span x-show="copied" x-cloak>Copiado!</span>
                </span>

                {{-- O mesmo markdown da página de shaping (issue #148). --}}
                <div
                    class="ml-auto"
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText(@js($this->pitch)); copied = true; setTimeout(() => copied = false, 1500)"
                >
                    <flux:button variant="ghost" size="xs" icon="clipboard-document" data-test="copy-pitch">
                        <span x-show="!copied">Copiar pitch</span>
                        <span x-show="copied" x-cloak>Copiado!</span>
                    </flux:button>
                </div>
            @endif
        </div>
        <flux:text class="mt-1">
            {{ $featureId ? 'Atualize os detalhes da feature.' : 'Crie uma nova feature para agrupar tasks relacionadas.' }}
        </flux:text>
    </div>

    <form wire:submit="save" class="space-y-4">
        {{-- Title --}}
        <flux:input
            wire:model="title"
            label="Título"
            placeholder="Nome da feature..."
            required
        />

        {{-- Project --}}
        <flux:select wire:model="projectId" label="Projeto">
            <option value="">Sem projeto</option>
            @foreach ($this->projects as $project)
                <option value="{{ $project->id }}">{{ $project->emoji }} {{ $project->name }}</option>
            @endforeach
        </flux:select>

        <div class="grid grid-cols-2 gap-4">
            {{-- Priority --}}
            <flux:select wire:model="priority" label="Prioridade">
                @foreach (ActivityPriority::cases() as $p)
                    <option value="{{ $p->value }}">{{ $p->label() }}</option>
                @endforeach
            </flux:select>

            {{-- Due Date --}}
            <flux:input
                wire:model="dueDate"
                type="date"
                label="Data limite"
            />
        </div>

        {{-- Spec (View/Edit Toggle) --}}
        <flux:field>
            <div class="flex items-center justify-between">
                <flux:label>Especificação</flux:label>
                @if (! ($this->feature && $this->feature->status === ActivityStatus::Done))
                    <flux:button
                        wire:click="toggleSpec"
                        variant="ghost"
                        size="xs"
                        :icon="$editingSpec ? 'eye' : 'pencil'"
                    />
                @endif
            </div>

            @if ($editingSpec && ! ($this->feature && $this->feature->status === ActivityStatus::Done))
                {{-- Modo Edição --}}
                <flux:editor
                    wire:model="spec"
                    placeholder="Descreva a feature em detalhes..."
                />
            @else
                {{-- Modo Visualização --}}
                @if ($spec)
                    <div class="max-h-60 overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-800/50 p-3">
                        <div class="prose prose-sm prose-invert max-w-none prose-headings:text-zinc-200 prose-p:text-zinc-300 prose-a:text-blue-400 prose-strong:text-zinc-200 prose-code:text-pink-400 prose-pre:bg-zinc-900 prose-li:text-zinc-300">
                            {!! Markdown::render($spec) !!}
                        </div>
                    </div>
                @else
                    <div class="flex items-center justify-center rounded-lg border border-dashed border-zinc-700 bg-zinc-800/30 p-6 text-zinc-500">
                        <span class="text-sm">Clique no ícone de editar para adicionar uma especificação</span>
                    </div>
                @endif
            @endif
        </flux:field>

        {{-- Feature Details (when editing) --}}
        @if ($this->feature)
            <flux:separator />

            {{-- Ciclo de vida da Spec (issue #146). Derivado dos movimentos
                 de status: não existe coluna nenhuma guardando estas datas. --}}
            <div class="rounded-lg border border-zinc-700 bg-zinc-800/50 p-4" data-test="spec-timeline">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="sm">Ciclo da Spec</flux:heading>
                    @if ($this->feature->isSpecPending())
                        <flux:badge size="sm" color="violet" icon="paper-airplane">Em aprovação</flux:badge>
                    @endif
                </div>

                <div class="flex items-stretch gap-1">
                    @foreach ($this->specTimeline as $step)
                        <div class="flex-1" wire:key="spec-step-{{ $step['key'] }}">
                            <div
                                @class([
                                    'h-1 rounded-full',
                                    'bg-violet-500' => $step['current'],
                                    'bg-emerald-500/70' => $step['done'],
                                    'bg-zinc-700' => ! $step['current'] && ! $step['done'],
                                ])
                            ></div>
                            <div class="mt-2 flex items-center gap-1">
                                <flux:icon
                                    :name="$step['icon']"
                                    @class([
                                        'size-3.5',
                                        'text-violet-400' => $step['current'],
                                        'text-emerald-400' => $step['done'],
                                        'text-zinc-600' => ! $step['current'] && ! $step['done'],
                                    ])
                                />
                                <span
                                    @class([
                                        'text-xs',
                                        'font-medium text-violet-300' => $step['current'],
                                        'text-zinc-300' => $step['done'],
                                        'text-zinc-600' => ! $step['current'] && ! $step['done'],
                                    ])
                                >{{ $step['label'] }}</span>
                            </div>
                            <span class="mt-0.5 block text-[0.65rem] text-zinc-500">
                                {{ $step['at']?->format('d/m/Y H:i') ?? '—' }}
                            </span>
                        </div>
                    @endforeach
                </div>

                {{-- Atalhos: açúcar em cima do status, nada mais. --}}
                @if ($this->feature->status !== ActivityStatus::Done)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($this->feature->status !== ActivityStatus::AwaitingApproval)
                            <flux:button
                                type="button"
                                wire:click="sendForApproval"
                                variant="ghost"
                                size="sm"
                                icon="paper-airplane"
                                data-test="spec-send"
                            >
                                Enviar p/ aprovação
                            </flux:button>
                        @endif

                        @if ($this->feature->status !== ActivityStatus::AwaitingValidation)
                            <flux:button
                                type="button"
                                wire:click="deliverForValidation"
                                variant="ghost"
                                size="sm"
                                icon="truck"
                                data-test="spec-deliver"
                            >
                                Entregar p/ validação
                            </flux:button>
                        @endif
                    </div>
                @endif

                {{-- Eficiência de fluxo desta spec: toque ÷ janela. --}}
                @if ($this->specEfficiency)
                    @php $efficiency = $this->specEfficiency; @endphp

                    <div class="mt-4 border-t border-zinc-700 pt-3" data-test="spec-efficiency">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-400">Eficiência de fluxo</span>
                            <span class="font-medium text-zinc-200">{{ $efficiency['percent'] }}%</span>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-700">
                            <div
                                class="h-full rounded-full bg-violet-500 transition-all duration-300"
                                style="width: {{ $efficiency['percent'] }}%"
                            ></div>
                        </div>
                        <span class="mt-2 block text-xs text-zinc-500">
                            {{ $this->formatSpan($efficiency['touch_minutes']) }} de toque em
                            {{ $this->formatSpan($efficiency['window_minutes']) }}
                            {{ $efficiency['open'] ? 'desde a aprovação (ainda contando)' : 'entre a aprovação e a validação' }}.
                            A aprovação fica fora do relógio; as esperas, dentro.
                        </span>
                    </div>
                @endif
            </div>

            {{-- Status & Progress --}}
            <div class="rounded-lg border border-zinc-700 bg-zinc-800/50 p-4">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="sm">Status</flux:heading>
                    <flux:badge size="sm" color="{{ $this->feature->status->color() }}" icon="{{ $this->feature->status->icon() }}">
                        {{ $this->feature->status->label() }}
                    </flux:badge>
                </div>

                {{-- Progress Bar --}}
                <div class="mb-2 flex items-center justify-between text-sm">
                    <span class="text-zinc-400">Progresso</span>
                    <span class="font-medium text-zinc-300">
                        {{ $this->feature->completedTasksCount() }}/{{ $this->feature->tasksCount() }} tasks ({{ $this->feature->progress }}%)
                    </span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-700">
                    <div
                        class="h-full rounded-full bg-{{ $this->feature->status->color() }}-500 transition-all duration-300"
                        style="width: {{ $this->feature->progress }}%"
                    ></div>
                </div>

                {{-- Time --}}
                <div class="mt-3 flex items-center justify-between text-sm">
                    <span class="text-zinc-400">Tempo total</span>
                    <span class="font-medium text-zinc-300">{{ $this->formatDuration($this->feature->total_time) }}</span>
                </div>
            </div>

            {{-- Timer Controls --}}
            <div class="flex items-center gap-3">
                @if ($this->feature->isRunning())
                    <flux:button
                        wire:click="stopTimer"
                        variant="danger"
                        icon="stop"
                        class="flex-1"
                    >
                        Parar Timer
                    </flux:button>
                @else
                    <flux:button
                        wire:click="startTimer"
                        variant="primary"
                        icon="play"
                        class="flex-1"
                    >
                        Iniciar Timer
                    </flux:button>
                @endif
            </div>

            {{-- Tasks List (children of this epic) --}}
            @if ($this->feature->children->isNotEmpty())
                <div>
                    <flux:heading size="sm" class="mb-2">Tasks</flux:heading>
                    <div class="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-800/50 p-2">
                        @foreach ($this->feature->children->sortBy('sort_order') as $task)
                            <button
                                type="button"
                                wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm transition hover:bg-zinc-700"
                            >
                                <div class="size-2 shrink-0 rounded-full bg-{{ $task->status->color() }}-400"></div>
                                <span class="flex-1 truncate text-zinc-300">{{ $task->title }}</span>
                                @if ($task->service_class)
                                    <flux:badge size="sm" color="{{ $task->service_class->color() }}">
                                        {{ $task->service_class->label() }}
                                    </flux:badge>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        {{-- Actions --}}
        <div class="flex items-center justify-between pt-2">
            <div>
                @if ($featureId && ! $showDeleteConfirm)
                    <flux:button
                        type="button"
                        wire:click="confirmDelete"
                        variant="ghost"
                        icon="trash"
                        class="text-red-400 hover:text-red-300"
                    >
                        Excluir
                    </flux:button>
                @endif

                @if ($showDeleteConfirm)
                    <div class="flex items-center gap-2">
                        <flux:text class="text-sm text-red-400">Confirma exclusão?</flux:text>
                        <flux:button
                            type="button"
                            wire:click="delete"
                            variant="danger"
                            size="sm"
                        >
                            Sim
                        </flux:button>
                        <flux:button
                            type="button"
                            wire:click="cancelDelete"
                            variant="ghost"
                            size="sm"
                        >
                            Não
                        </flux:button>
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <flux:button
                    type="button"
                    x-on:click="$flux.modal('feature-modal').close()"
                    variant="ghost"
                >
                    Cancelar
                </flux:button>
                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                >
                    {{ $featureId ? 'Salvar' : 'Criar Feature' }}
                </flux:button>
            </div>
        </div>
    </form>
</flux:modal>
