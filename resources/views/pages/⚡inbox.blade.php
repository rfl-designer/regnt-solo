<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showDeleteModal = false;

    public ?int $deletingTaskId = null;

    public ?string $deletingTaskTitle = null;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Task>
     */
    #[Computed]
    public function tasks(): \Illuminate\Database\Eloquent\Collection
    {
        return Task::inbox()
            ->with('project')
            ->latest()
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::active()->orderBy('name')->get();
    }

    public function assignProject(int $taskId, ?string $projectId): void
    {
        $task = Task::inbox()->findOrFail($taskId);

        $task->update([
            'project_id' => $projectId !== '' ? $projectId : null,
        ]);

        unset($this->tasks);

        Flux::toast(variant: 'success', heading: 'Projeto atualizado', text: $task->title);
    }

    public function moveToBacklog(int $taskId): void
    {
        $task = Task::inbox()->findOrFail($taskId);

        $task->update(['status' => TaskStatus::Backlog]);

        unset($this->tasks);

        $this->dispatch('task-moved');

        Flux::toast(variant: 'success', heading: 'Movida para Backlog', text: $task->title);
    }

    public function confirmDelete(int $taskId): void
    {
        $task = Task::inbox()->findOrFail($taskId);

        $this->deletingTaskId = $task->id;
        $this->deletingTaskTitle = $task->title;
        $this->showDeleteModal = true;
    }

    public function deleteTask(): void
    {
        if ($this->deletingTaskId === null) {
            return;
        }

        $task = Task::inbox()->findOrFail($this->deletingTaskId);
        $title = $task->title;

        $task->delete();

        $this->reset('deletingTaskId', 'deletingTaskTitle', 'showDeleteModal');

        unset($this->tasks);

        $this->dispatch('task-moved');

        Flux::toast(variant: 'success', heading: 'Task excluída', text: $title);
    }

    #[On('task-created')]
    public function refreshTasks(): void
    {
        unset($this->tasks);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <flux:heading size="xl">Inbox</flux:heading>

    @if ($this->tasks->isEmpty())
        <div class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <flux:icon name="inbox" class="mx-auto mb-3 size-12 text-zinc-500" />
                <flux:heading size="lg">Nenhuma task no inbox</flux:heading>
                <flux:text class="mt-1">Pressione <kbd class="rounded border border-zinc-600 bg-zinc-700 px-1.5 py-0.5 text-xs">N</kbd> para criar.</flux:text>
            </div>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Task</flux:table.column>
                <flux:table.column>Projeto</flux:table.column>
                <flux:table.column>Criada</flux:table.column>
                <flux:table.column align="end">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->tasks as $task)
                    <flux:table.row :key="$task->id">
                        <flux:table.cell variant="strong">
                            {{ $task->title }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:select
                                wire:change="assignProject({{ $task->id }}, $event.target.value)"
                                size="sm"
                                class="min-w-40"
                            >
                                <option value="">Sem projeto</option>
                                @foreach ($this->projects as $project)
                                    <option
                                        value="{{ $project->id }}"
                                        @selected($task->project_id === $project->id)
                                    >
                                        {{ $project->emoji }} {{ $project->name }}
                                    </option>
                                @endforeach
                            </flux:select>
                        </flux:table.cell>

                        <flux:table.cell>
                            {{ $task->created_at->diffForHumans() }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    wire:click="moveToBacklog({{ $task->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="moveToBacklog({{ $task->id }})"
                                >
                                    → Backlog
                                </flux:button>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="confirmDelete({{ $task->id }})"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Delete confirmation modal --}}
    <flux:modal wire:model.self="showDeleteModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Excluir task</flux:heading>
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
