<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'project')]
    public ?int $projectFilter = null;

    /**
     * Where archived items live now (issue #147). The board's Feito column
     * shows only what hasn't been filed away, so this filter is the way
     * back to everything the morning ritual has archived — the history is
     * hidden, never deleted.
     */
    #[Url(as: 'archived')]
    public bool $showArchived = false;

    public bool $showDeleteModal = false;

    public ?int $deletingTaskId = null;

    public ?string $deletingTaskTitle = null;

    /**
     * Personal tasks (type=Task) grouped by status, honoring the active filters.
     *
     * @return array<string, \Illuminate\Support\Collection<int, Activity>>
     */
    #[Computed]
    public function tasksByStatus(): array
    {
        $query = Activity::query()->tasks()->with(['project', 'parent']);

        if ($this->showArchived) {
            $query->archived();
        } else {
            $query->notArchived();
        }

        if ($this->search !== '') {
            $query->whereRaw('lower(title) like ?', ['%'.mb_strtolower($this->search).'%']);
        }

        if ($this->projectFilter !== null) {
            $query->where('project_id', $this->projectFilter);
        }

        $tasks = $query->ordered()->get();

        $grouped = [];

        foreach (ActivityStatus::cases() as $status) {
            $grouped[$status->value] = $tasks
                ->filter(fn (Activity $task): bool => $task->status === $status)
                ->values();
        }

        return $grouped;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::active()->orderBy('name')->get();
    }

    /**
     * Toggle a task between done and todo, marking completion timestamps.
     */
    public function toggleComplete(int $taskId): void
    {
        $task = Activity::query()->tasks()->findOrFail($taskId);

        if ($task->status === ActivityStatus::Done) {
            $task->update([
                'status' => ActivityStatus::Todo,
                'completed_at' => null,
            ]);
        } else {
            $task->markAsDone();
        }

        unset($this->tasksByStatus);

        $this->dispatch('task-updated');
    }

    public function confirmDelete(int $taskId): void
    {
        $task = Activity::query()->tasks()->findOrFail($taskId);

        $this->deletingTaskId = $task->id;
        $this->deletingTaskTitle = $task->title;
        $this->showDeleteModal = true;
    }

    public function deleteTask(): void
    {
        if ($this->deletingTaskId === null) {
            return;
        }

        $task = Activity::query()->tasks()->findOrFail($this->deletingTaskId);
        $title = $task->title;

        $task->delete();

        $this->reset('deletingTaskId', 'deletingTaskTitle', 'showDeleteModal');

        unset($this->tasksByStatus);

        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Task excluída', text: $title);
    }

    #[On('task-created')]
    #[On('task-updated')]
    #[On('task-moved')]
    public function refreshTasks(): void
    {
        unset($this->tasksByStatus);
    }

    public function updatedShowArchived(): void
    {
        unset($this->tasksByStatus);
    }

    /**
     * @return list<ActivityStatus>
     */
    public function statuses(): array
    {
        return ActivityStatus::cases();
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:icon name="check-circle" class="size-7 text-emerald-400" />
            <flux:heading size="xl">Tarefas</flux:heading>
        </div>

        <flux:modal.trigger name="quick-add">
            <flux:button variant="primary" icon="plus" size="sm">Nova task</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:text class="-mt-4 text-sm text-zinc-400">
        Recados operacionais e pessoais. Podem existir sem projeto ou ligados a um.
    </flux:text>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <flux:input
            icon="magnifying-glass"
            wire:model.live.debounce.300ms="search"
            placeholder="Buscar tarefas..."
            size="sm"
            class="max-w-xs"
        />

        <flux:select wire:model.live="projectFilter" size="sm" class="max-w-48">
            <option value="">Todos os projetos</option>
            @foreach ($this->projects as $project)
                <option value="{{ $project->id }}">{{ $project->emoji }} {{ $project->name }}</option>
            @endforeach
        </flux:select>

        <flux:button
            wire:click="$toggle('showArchived')"
            size="sm"
            :variant="$showArchived ? 'primary' : 'ghost'"
            icon="archive-box"
            data-test="toggle-archived"
        >
            Arquivadas
        </flux:button>
    </div>

    @php
        $totalTasks = collect($this->tasksByStatus)->sum(fn ($tasks) => $tasks->count());
    @endphp

    @if ($totalTasks === 0)
        <div class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <flux:icon name="check-circle" class="mx-auto mb-3 size-12 text-zinc-500" />
                <flux:heading size="lg">{{ $showArchived ? 'Nenhuma tarefa arquivada' : 'Nenhuma tarefa' }}</flux:heading>
                <flux:text class="mt-1">Crie um recado para começar.</flux:text>
                <flux:text class="mt-1 text-sm text-zinc-500">
                    Pressione <kbd class="rounded border border-zinc-600 bg-zinc-700 px-1.5 py-0.5 text-xs">Ctrl</kbd>
                    + <kbd class="rounded border border-zinc-600 bg-zinc-700 px-1.5 py-0.5 text-xs">N</kbd> para criar.
                </flux:text>
            </div>
        </div>
    @else
        <div class="flex flex-col gap-6">
            @foreach ($this->statuses() as $status)
                @php $statusTasks = $this->tasksByStatus[$status->value]; @endphp

                @if ($statusTasks->isNotEmpty())
                    <div class="flex flex-col gap-2">
                        {{-- Section header --}}
                        <div class="flex items-center gap-2">
                            <flux:icon :name="$status->icon()" class="size-4 text-{{ $status->color() }}-400" />
                            <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                            <flux:badge size="sm" color="{{ $status->color() }}">{{ $statusTasks->count() }}</flux:badge>
                        </div>

                        {{-- Task rows --}}
                        <div class="flex flex-col gap-2">
                            @foreach ($statusTasks as $task)
                                <div
                                    wire:key="task-{{ $task->id }}"
                                    class="group flex items-center gap-3 rounded-lg border border-zinc-700 bg-zinc-800 p-3 transition hover:border-zinc-500"
                                >
                                    <flux:checkbox
                                        :checked="$task->status === \App\Enums\ActivityStatus::Done"
                                        wire:click="toggleComplete({{ $task->id }})"
                                    />

                                    <button
                                        type="button"
                                        wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                        class="min-w-0 flex-1 text-left"
                                    >
                                        <span class="text-sm font-medium text-zinc-200 {{ $task->status === \App\Enums\ActivityStatus::Done ? 'line-through text-zinc-500' : '' }}">
                                            {{ $task->title }}
                                        </span>
                                    </button>

                                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                                        @if ($task->service_class)
                                            <flux:badge size="sm" color="{{ $task->service_class->color() }}" icon="{{ $task->service_class->icon() }}">
                                                {{ $task->service_class->label() }}
                                            </flux:badge>
                                        @endif

                                        @if ($task->project)
                                            <flux:badge size="sm" color="zinc" icon="folder">
                                                {{ $task->project->emoji }} {{ $task->project->name }}
                                            </flux:badge>
                                        @endif

                                        @if ($task->parent)
                                            <flux:badge size="sm" color="violet" icon="link">
                                                {{ \Illuminate\Support\Str::limit($task->parent->title, 24) }}
                                            </flux:badge>
                                        @endif

                                        @if ($task->isOverdue())
                                            <flux:badge size="sm" color="red" icon="exclamation-triangle">
                                                {{ $task->due_date->diffForHumans() }}
                                            </flux:badge>
                                        @elseif ($task->due_date)
                                            <flux:badge size="sm" color="zinc" icon="calendar-days">
                                                {{ $task->due_date->format('d/m') }}
                                            </flux:badge>
                                        @endif

                                        @if ($task->isRunning())
                                            <flux:badge size="sm" color="emerald" class="animate-pulse">Timer</flux:badge>
                                        @endif

                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            class="opacity-0 transition group-hover:opacity-100"
                                            wire:click="confirmDelete({{ $task->id }})"
                                        />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- Delete confirmation modal --}}
    <flux:modal wire:model.self="showDeleteModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Excluir tarefa</flux:heading>
                <flux:text class="mt-1">
                    Tem certeza que deseja excluir <strong>{{ $deletingTaskTitle }}</strong>? Esta ação não pode ser desfeita.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteTask" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="deleteTask">Excluir</span>
                    <span wire:loading wire:target="deleteTask">Excluindo...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
