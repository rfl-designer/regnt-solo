<?php

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public string $slug = '';

    public string $tab = 'tasks';

    /** @var list<TaskStatus> */
    public array $kanbanStatuses = [];

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $this->kanbanStatuses = [
            TaskStatus::Backlog,
            TaskStatus::Todo,
            TaskStatus::Doing,
            TaskStatus::Done,
        ];
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()
            ->where('slug', $this->slug)
            ->with(['tasks.timeEntries', 'tasks.commits', 'documents'])
            ->firstOrFail();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Document>
     */
    #[Computed]
    public function projectDocuments(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->project->documents->sortByDesc('is_pinned')->sortBy('sort_order');
    }

    /**
     * @return array<string, \Illuminate\Support\Collection<int, \App\Models\Task>>
     */
    #[Computed]
    public function tasksByStatus(): array
    {
        $grouped = [];

        foreach (TaskStatus::cases() as $status) {
            $grouped[$status->value] = $this->project->tasks
                ->where('status', $status)
                ->sortBy('sort_order');
        }

        return $grouped;
    }

    /**
     * @return array{total: int, by_status: array<string, int>, total_hours: float, completion_percent: float}
     */
    #[Computed]
    public function metrics(): array
    {
        $tasks = $this->project->tasks;
        $total = $tasks->count();
        $doneCount = $tasks->where('status', TaskStatus::Done)->count();

        $byStatus = [];
        foreach (TaskStatus::cases() as $status) {
            $byStatus[$status->value] = $tasks->where('status', $status)->count();
        }

        $totalMinutes = $tasks->flatMap->timeEntries->sum(function ($entry) {
            $end = $entry->stopped_at ?? now();

            return $entry->started_at->diffInMinutes($end);
        });

        $totalCommits = $tasks->sum(fn ($task) => $task->commits->count());
        $totalFilesChanged = $tasks->sum(fn ($task) => $task->commits->sum('files_changed'));

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'total_hours' => round($totalMinutes / 60, 1),
            'completion_percent' => $total > 0 ? round(($doneCount / $total) * 100, 1) : 0,
            'total_commits' => $totalCommits,
            'total_files_changed' => $totalFilesChanged,
        ];
    }

    public function archiveProject(): void
    {
        $project = $this->project;
        $project->update(['status' => ProjectStatus::Archived]);

        unset($this->project, $this->tasksByStatus, $this->metrics);

        $this->dispatch('project-updated');

        Flux::toast(variant: 'success', heading: 'Projeto arquivado', text: $project->name);
    }

    public function activateProject(): void
    {
        $project = $this->project;
        $project->update(['status' => ProjectStatus::Active]);

        unset($this->project, $this->tasksByStatus, $this->metrics);

        $this->dispatch('project-updated');

        Flux::toast(variant: 'success', heading: 'Projeto reativado', text: $project->name);
    }

    #[On('task-created')]
    #[On('task-updated')]
    #[On('project-updated')]
    #[On('document-saved')]
    public function refreshProject(): void
    {
        unset($this->project, $this->tasksByStatus, $this->metrics, $this->projectDocuments);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
            <span class="text-4xl">{{ $this->project->emoji }}</span>

            <div class="flex flex-col gap-1">
                <flux:heading size="xl">{{ $this->project->name }}</flux:heading>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge size="sm" color="{{ $this->project->status->color() }}" icon="{{ $this->project->status->icon() }}">
                        {{ $this->project->status->label() }}
                    </flux:badge>

                    <flux:badge size="sm" color="{{ $this->project->priority->color() }}" icon="{{ $this->project->priority->icon() }}">
                        {{ $this->project->priority->label() }}
                    </flux:badge>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                variant="ghost"
                icon="arrow-left"
                href="{{ route('projects') }}"
                wire:navigate
            >
                Voltar
            </flux:button>

            <flux:button
                variant="ghost"
                icon="pencil-square"
                wire:click="$dispatch('edit-project', { projectId: {{ $this->project->id }} })"
            >
                Editar
            </flux:button>

            @if ($this->project->status !== ProjectStatus::Archived)
                <flux:button
                    variant="ghost"
                    icon="archive-box"
                    wire:click="archiveProject"
                    wire:loading.attr="disabled"
                    wire:target="archiveProject"
                    wire:confirm="Tem certeza que deseja arquivar este projeto?"
                >
                    Arquivar
                </flux:button>
            @else
                <flux:button
                    variant="ghost"
                    icon="play-circle"
                    wire:click="activateProject"
                    wire:loading.attr="disabled"
                    wire:target="activateProject"
                >
                    Reativar
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Description --}}
    @if ($this->project->description)
        <flux:text class="text-sm text-zinc-400">{{ $this->project->description }}</flux:text>
    @endif

    {{-- Tabs --}}
    <flux:tab.group>
        <flux:tabs wire:model="tab">
            <flux:tab name="tasks" icon="clipboard-document-list">Tasks</flux:tab>
            <flux:tab name="docs" icon="document-text">Docs</flux:tab>
            <flux:tab name="metrics" icon="chart-bar-square">Métricas</flux:tab>
        </flux:tabs>

        {{-- Tab Panel: Tasks (Mini-Kanban) --}}
        <flux:tab.panel name="tasks">
            <div class="flex flex-col gap-4">
                {{-- New Task button --}}
                <div class="flex justify-end">
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="plus"
                        wire:click="$dispatch('open-task-quick-add')"
                    >
                        Nova Task
                    </flux:button>
                </div>

                {{-- Mini-Kanban --}}
                <div class="flex gap-4 overflow-x-auto pb-4">
                    @foreach ($kanbanStatuses as $status)
                        @php
                            $tasks = $this->tasksByStatus[$status->value] ?? collect();
                        @endphp

                        <div class="flex w-64 shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50">
                            {{-- Column Header --}}
                            <div class="flex items-center justify-between border-b border-zinc-700 px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    <flux:icon :name="$status->icon()" class="size-4 text-{{ $status->color() }}-400" />
                                    <span class="text-sm font-medium text-zinc-300">{{ $status->label() }}</span>
                                </div>
                                <flux:badge size="sm" color="{{ $status->color() }}">{{ $tasks->count() }}</flux:badge>
                            </div>

                            {{-- Tasks List --}}
                            <div class="flex-1 overflow-y-auto p-2" style="max-height: 28rem;">
                                @forelse ($tasks as $task)
                                    <div
                                        wire:key="task-{{ $task->id }}"
                                        class="mb-2 cursor-pointer rounded-lg border border-zinc-700 bg-zinc-800 p-2.5 transition hover:border-zinc-500"
                                        wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                    >
                                        <span class="line-clamp-2 text-sm font-medium text-zinc-200">{{ $task->title }}</span>

                                        <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                            @if ($task->priority)
                                                <flux:badge size="sm" color="{{ $task->priority->color() }}" icon="{{ $task->priority->icon() }}">
                                                    {{ $task->priority->label() }}
                                                </flux:badge>
                                            @endif

                                            @if ($task->estimated_minutes)
                                                <flux:badge size="sm" color="zinc" icon="clock">
                                                    {{ $task->estimated_minutes }}m
                                                </flux:badge>
                                            @endif

                                            @if ($task->isOverdue())
                                                <flux:badge size="sm" color="red" icon="exclamation-triangle">
                                                    {{ $task->due_date->diffForHumans() }}
                                                </flux:badge>
                                            @endif

                                            @if ($task->isRunning())
                                                <flux:badge size="sm" color="emerald" class="animate-pulse">
                                                    <div class="mr-1 size-2 rounded-full bg-emerald-400"></div>
                                                    Timer
                                                </flux:badge>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="py-6 text-center text-xs text-zinc-600">
                                        Nenhuma task
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </flux:tab.panel>

        {{-- Tab Panel: Docs --}}
        <flux:tab.panel name="docs">
            <div class="flex flex-col gap-4">
                {{-- New Document button --}}
                <div class="flex justify-end">
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="plus"
                        href="{{ route('document.create') }}?project={{ $this->project->id }}"
                        wire:navigate
                    >
                        Novo Documento
                    </flux:button>
                </div>

                @if ($this->projectDocuments->isEmpty())
                    <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-12">
                        <flux:icon name="document-text" class="mb-3 size-10 text-zinc-600" />
                        <flux:text class="text-sm text-zinc-500">Nenhum documento neste projeto.</flux:text>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        @foreach ($this->projectDocuments as $document)
                            <div
                                wire:key="doc-{{ $document->id }}"
                                class="flex items-start gap-3 rounded-lg border border-zinc-700 bg-zinc-900/50 p-3 transition hover:border-zinc-500"
                            >
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-{{ $document->type->color() }}-500/10">
                                    <flux:icon :name="$document->type->icon()" class="size-4 text-{{ $document->type->color() }}-400" />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('document.view', $document->slug) }}" wire:navigate class="text-sm font-medium text-zinc-200 hover:text-white">
                                        {{ $document->title }}
                                    </a>
                                    <div class="mt-1 flex items-center gap-2">
                                        <flux:badge size="sm" :color="$document->type->color()">
                                            {{ $document->type->label() }}
                                        </flux:badge>
                                        @if ($document->is_pinned)
                                            <flux:icon name="star" variant="micro" class="size-3 text-amber-400" />
                                        @endif
                                    </div>
                                    <flux:text class="mt-1 line-clamp-2 text-xs text-zinc-500">
                                        {{ $document->excerpt(100) }}
                                    </flux:text>
                                </div>

                                <flux:button
                                    variant="ghost"
                                    size="xs"
                                    icon="pencil-square"
                                    href="{{ route('document.edit', $document->slug) }}"
                                    wire:navigate
                                />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </flux:tab.panel>

        {{-- Tab Panel: Métricas --}}
        <flux:tab.panel name="metrics">
            @php
                $metrics = $this->metrics;
            @endphp

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                {{-- Total de Tasks --}}
                <flux:card class="space-y-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="clipboard-document-list" class="size-5 text-zinc-400" />
                        <flux:text class="text-sm text-zinc-400">Total de Tasks</flux:text>
                    </div>
                    <flux:heading size="xl">{{ $metrics['total'] }}</flux:heading>
                </flux:card>

                {{-- Horas Trabalhadas --}}
                <flux:card class="space-y-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="clock" class="size-5 text-zinc-400" />
                        <flux:text class="text-sm text-zinc-400">Horas Trabalhadas</flux:text>
                    </div>
                    <flux:heading size="xl">{{ $metrics['total_hours'] }}h</flux:heading>
                </flux:card>

                {{-- Conclusão --}}
                <flux:card class="space-y-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="check-circle" class="size-5 text-emerald-400" />
                        <flux:text class="text-sm text-zinc-400">Conclusão</flux:text>
                    </div>
                    <flux:heading size="xl">{{ $metrics['completion_percent'] }}%</flux:heading>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-700">
                        <div
                            class="h-full rounded-full bg-emerald-500 transition-all duration-500"
                            style="width: {{ $metrics['completion_percent'] }}%"
                        ></div>
                    </div>
                </flux:card>

                {{-- Tasks Concluídas --}}
                <flux:card class="space-y-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="trophy" class="size-5 text-amber-400" />
                        <flux:text class="text-sm text-zinc-400">Concluídas</flux:text>
                    </div>
                    <flux:heading size="xl">{{ $metrics['by_status']['done'] ?? 0 }}</flux:heading>
                </flux:card>

                {{-- Total de Commits --}}
                <flux:card class="space-y-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="code-bracket" class="size-5 text-violet-400" />
                        <flux:text class="text-sm text-zinc-400">Commits</flux:text>
                    </div>
                    <flux:heading size="xl">{{ $metrics['total_commits'] }}</flux:heading>
                </flux:card>

                {{-- Arquivos Alterados --}}
                <flux:card class="space-y-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="document-text" class="size-5 text-sky-400" />
                        <flux:text class="text-sm text-zinc-400">Arquivos Alterados</flux:text>
                    </div>
                    <flux:heading size="xl">{{ $metrics['total_files_changed'] }}</flux:heading>
                </flux:card>
            </div>

            {{-- Tasks por Status --}}
            <div class="mt-6">
                <flux:heading size="sm" class="mb-3">Tasks por Status</flux:heading>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach (TaskStatus::cases() as $status)
                        @php
                            $count = $metrics['by_status'][$status->value] ?? 0;
                        @endphp

                        <div class="flex items-center gap-3 rounded-lg border border-zinc-700 bg-zinc-900/50 px-4 py-3">
                            <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                            <div class="flex flex-1 items-center justify-between">
                                <span class="text-sm text-zinc-300">{{ $status->label() }}</span>
                                <flux:badge size="sm" color="{{ $status->color() }}">{{ $count }}</flux:badge>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </flux:tab.panel>
    </flux:tab.group>
</div>
