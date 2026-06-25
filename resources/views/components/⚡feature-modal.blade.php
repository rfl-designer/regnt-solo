<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use App\Models\TimeEntry;
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

        return Activity::with(['project', 'children.timeEntries', 'timeEntries'])->find($this->featureId);
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

        unset($this->feature);
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

        unset($this->feature);
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

        unset($this->feature);
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
                                @if ($task->priority)
                                    <flux:badge size="sm" color="{{ $task->priority->color() }}">
                                        {{ $task->priority->label() }}
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
