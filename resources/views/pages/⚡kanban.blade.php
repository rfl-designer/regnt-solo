<?php

use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $filterProject = '';

    #[Url]
    public string $filterPriority = '';

    #[Url(as: 'overdue')]
    public bool $filterOverdue = false;

    /** @var array<string, int> */
    public array $limits = [
        'backlog' => 20,
        'todo' => 20,
        'doing' => 20,
        'done' => 20,
    ];

    /** @var list<TaskStatus> */
    public array $kanbanStatuses = [];

    public function mount(): void
    {
        $this->kanbanStatuses = [
            TaskStatus::Backlog,
            TaskStatus::Todo,
            TaskStatus::Doing,
            TaskStatus::Done,
        ];
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
     * Get tasks for a specific column, optionally only those with a project.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Task>
     */
    public function getColumnTasks(TaskStatus $status, bool $withProject = true): \Illuminate\Database\Eloquent\Collection
    {
        $query = $this->buildColumnQuery($status);

        if ($withProject) {
            $query->whereNotNull('project_id');
        } else {
            $query->whereNull('project_id');
        }

        return $query->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->limit($this->limits[$status->value])
            ->get();
    }

    /**
     * Get total count for a column (for "load more" button).
     */
    public function getColumnTotal(TaskStatus $status): int
    {
        return $this->buildColumnQuery($status)->count();
    }

    /**
     * Get total estimated minutes for a column.
     */
    public function getColumnEstimate(TaskStatus $status): int
    {
        return (int) $this->buildColumnQuery($status)->sum('estimated_minutes');
    }

    /**
     * Format minutes as human-readable duration (e.g., "2h 30m" or "45m").
     */
    public function formatDuration(int $minutes): string
    {
        if ($minutes === 0) {
            return '';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}m";
        }

        if ($hours > 0) {
            return "{$hours}h";
        }

        return "{$mins}m";
    }

    /**
     * Check if a column has unassigned tasks.
     */
    public function hasUnassignedTasks(TaskStatus $status): bool
    {
        return $this->buildColumnQuery($status)->whereNull('project_id')->exists();
    }

    public function loadMore(string $status): void
    {
        $this->limits[$status] += 20;
    }

    public function handleSort(int|string $id, int $position, string $groupId): void
    {
        $task = Task::findOrFail((int) $id);
        $newStatus = TaskStatus::from($groupId);

        DB::transaction(function () use ($task, $newStatus, $position): void {
            if ($newStatus === TaskStatus::Done && $task->status !== TaskStatus::Done) {
                // Add to daily plan if not already there (Observer syncs completed_at)
                $dailyPlan = DailyPlan::getOrCreateForDate(Carbon::today());

                if (! $dailyPlan->tasks()->where('task_id', $task->id)->exists()) {
                    $maxOrder = $dailyPlan->tasks()->max('daily_plan_task.sort_order') ?? -1;
                    $dailyPlan->tasks()->attach($task->id, ['sort_order' => $maxOrder + 1]);
                }

                $task->markAsDone();

                Flux::toast(variant: 'success', heading: 'Task concluída', text: $task->title);
            } else {
                $task->update([
                    'status' => $newStatus,
                    'sort_order' => $position,
                ]);
            }

            $this->recalculateSortOrder($newStatus);
        });
    }

    #[On('task-updated')]
    #[On('task-created')]
    public function refreshBoard(): void
    {
        unset($this->projects);
    }

    /**
     * Build the base query for a column with filters applied.
     */
    private function buildColumnQuery(TaskStatus $status): \Illuminate\Database\Eloquent\Builder
    {
        $query = Task::query()
            ->with('project', 'timeEntries')
            ->withCount('commits')
            ->where('status', $status);

        if ($status === TaskStatus::Done) {
            $query->whereBetween('completed_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ]);
        }

        if ($this->filterProject !== '') {
            $query->where('project_id', (int) $this->filterProject);
        }

        if ($this->filterPriority !== '') {
            $query->where('priority', $this->filterPriority);
        }

        if ($this->filterOverdue) {
            $query->whereNotNull('due_date')
                ->where('due_date', '<', Carbon::today());
        }

        return $query;
    }

    private function recalculateSortOrder(TaskStatus $status): void
    {
        $query = Task::query()->where('status', $status);

        if ($status === TaskStatus::Done) {
            $query->whereBetween('completed_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ]);
        }

        $tasks = $query->orderBy('sort_order')->orderBy('created_at', 'desc')->get();

        foreach ($tasks as $index => $task) {
            if ($task->sort_order !== $index) {
                $task->update(['sort_order' => $index]);
            }
        }
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col p-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Kanban</flux:heading>

        <div class="flex items-center gap-3">
            {{-- Project filter --}}
            <flux:select wire:model.live="filterProject" size="sm" class="min-w-40">
                <option value="">Todos os projetos</option>
                @foreach ($this->projects as $project)
                    <option value="{{ $project->id }}">{{ $project->emoji }} {{ $project->name }}</option>
                @endforeach
            </flux:select>

            {{-- Priority filter --}}
            <flux:select wire:model.live="filterPriority" size="sm" class="min-w-32">
                <option value="">Todas prioridades</option>
                @foreach (App\Enums\TaskPriority::cases() as $priority)
                    <option value="{{ $priority->value }}">{{ $priority->label() }}</option>
                @endforeach
            </flux:select>

            {{-- Overdue toggle --}}
            <flux:button
                wire:click="$toggle('filterOverdue')"
                size="sm"
                :variant="$filterOverdue ? 'primary' : 'ghost'"
                icon="exclamation-triangle"
            >
                Atrasadas
            </flux:button>
        </div>
    </div>

    {{-- Separator --}}
    <flux:separator class="my-4" />

    {{-- Kanban Board --}}
    <div
        x-data="{
            doneCollapsed: localStorage.getItem('kanban-done-collapsed') === 'true',
            toggleDoneColumn() {
                this.doneCollapsed = !this.doneCollapsed;
                localStorage.setItem('kanban-done-collapsed', this.doneCollapsed);
            }
        }"
        class="flex flex-1 gap-4 overflow-x-auto pb-4"
    >
        @foreach ($kanbanStatuses as $status)
            @php
                $tasks = $this->getColumnTasks($status, withProject: true);
                $unassignedTasks = $this->getColumnTasks($status, withProject: false);
                $total = $this->getColumnTotal($status);
                $estimate = $this->getColumnEstimate($status);
                $estimateFormatted = $this->formatDuration($estimate);
                $limit = $limits[$status->value];
                $hasMore = $total > $limit;
            @endphp

            @php
                $isDone = $status === \App\Enums\TaskStatus::Done;
            @endphp

            <div
                @if ($isDone)
                    x-bind:class="doneCollapsed ? 'w-14' : 'w-80'"
                @endif
                class="{{ $isDone ? '' : 'w-80' }} flex shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50 transition-all duration-300 ease-in-out"
            >
                {{-- Column Header --}}
                @if ($isDone)
                    {{-- Done column header with collapse support --}}
                    <div
                        @click="toggleDoneColumn()"
                        x-bind:class="doneCollapsed ? 'flex-col items-center py-4 cursor-pointer hover:bg-zinc-800/50' : 'flex-row items-center justify-between'"
                        class="flex border-b border-zinc-700 px-4 py-3 transition-all duration-200"
                    >
                        {{-- Expanded state --}}
                        <template x-if="!doneCollapsed">
                            <div class="flex w-full items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                    <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if ($estimateFormatted)
                                        <flux:badge size="sm" color="zinc" icon="clock">{{ $estimateFormatted }}</flux:badge>
                                    @endif
                                    <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                    <button
                                        type="button"
                                        @click.stop="toggleDoneColumn()"
                                        class="ml-1 rounded p-1 text-zinc-500 transition hover:bg-zinc-700 hover:text-zinc-300"
                                        title="Colapsar coluna"
                                    >
                                        <flux:icon name="chevron-right" class="size-4" />
                                    </button>
                                </div>
                            </div>
                        </template>

                        {{-- Collapsed state --}}
                        <template x-if="doneCollapsed">
                            <div class="flex flex-col items-center gap-2">
                                <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                <flux:icon name="chevron-left" class="size-4 text-zinc-500" />
                            </div>
                        </template>
                    </div>
                @else
                    {{-- Regular column header --}}
                    <div class="flex items-center justify-between border-b border-zinc-700 px-4 py-3">
                        <div class="flex items-center gap-2">
                            <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                            <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($estimateFormatted)
                                <flux:badge size="sm" color="zinc" icon="clock">{{ $estimateFormatted }}</flux:badge>
                            @endif
                            <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                        </div>
                    </div>
                @endif

                {{-- Tasks List --}}
                <div
                    @if ($isDone) x-show="!doneCollapsed" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @endif
                    class="flex-1 overflow-y-auto p-2"
                >
                    <ul
                        wire:sort="handleSort"
                        wire:sort:group="tasks"
                        wire:sort:group-id="{{ $status->value }}"
                        class="flex min-h-[2rem] flex-col gap-2"
                    >
                        @forelse ($tasks as $task)
                            <li wire:key="task-{{ $task->id }}" wire:sort:item="{{ $task->id }}">
                                <div
                                    class="group cursor-pointer rounded-lg border border-zinc-700 bg-zinc-800 p-3 transition hover:border-zinc-500"
                                    wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                >
                                    {{-- Card Top: Handle + Title --}}
                                    <div class="flex items-start gap-2">
                                        <div wire:sort:handle class="mt-0.5 shrink-0 cursor-grab text-zinc-600 hover:text-zinc-400">
                                            <flux:icon name="grip-vertical" class="size-4" />
                                        </div>
                                        <span class="line-clamp-2 flex-1 text-sm font-medium text-zinc-200">{{ $task->title }}</span>
                                    </div>

                                    {{-- Project Info --}}
                                    @if ($task->project)
                                        <div class="mt-2 flex items-center gap-1.5 border-l-2 pl-2" style="border-color: {{ $task->project->color }}">
                                            <span class="text-xs">{{ $task->project->emoji }}</span>
                                            <span class="truncate text-xs text-zinc-400">{{ $task->project->name }}</span>
                                        </div>
                                    @endif

                                    {{-- Badges Row --}}
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5" wire:sort:ignore>
                                        {{-- Priority Badge --}}
                                        @if ($task->priority)
                                            <flux:badge size="sm" color="{{ $task->priority->color() }}" icon="{{ $task->priority->icon() }}">
                                                {{ $task->priority->label() }}
                                            </flux:badge>
                                        @endif

                                        {{-- Estimate Badge --}}
                                        @if ($task->estimated_minutes)
                                            <flux:badge size="sm" color="zinc" icon="clock">
                                                {{ $task->estimated_minutes }}m
                                            </flux:badge>
                                        @endif

                                        {{-- Overdue Badge --}}
                                        @if ($task->isOverdue())
                                            <flux:badge size="sm" color="red" icon="exclamation-triangle">
                                                {{ $task->due_date->diffForHumans() }}
                                            </flux:badge>
                                        @endif

                                        {{-- Running Timer --}}
                                        @if ($task->isRunning())
                                            <flux:badge size="sm" color="emerald" class="animate-pulse">
                                                <div class="mr-1 size-2 rounded-full bg-emerald-400"></div>
                                                Timer
                                            </flux:badge>
                                        @endif

                                        {{-- Commits Badge --}}
                                        @if ($task->commits_count > 0)
                                            <flux:badge size="sm" color="zinc" icon="code-bracket">
                                                {{ $task->commits_count }} {{ $task->commits_count === 1 ? 'commit' : 'commits' }}
                                            </flux:badge>
                                        @endif

                                        {{-- Session Badge --}}
                                        @if ($task->isSessionTask())
                                            <flux:badge size="sm" color="violet" class="gap-1">
                                                🤖 Sessão
                                            </flux:badge>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @empty
                            @if ($unassignedTasks->isEmpty())
                                <li class="py-8 text-center text-sm text-zinc-600">
                                    Nenhuma task
                                </li>
                            @endif
                        @endforelse
                    </ul>

                    {{-- Unassigned Tasks Section --}}
                    @if ($unassignedTasks->isNotEmpty())
                        <div class="mt-3 border-t border-dashed border-zinc-700 pt-3">
                            <div class="mb-2 flex items-center gap-1.5 px-1">
                                <flux:icon name="folder" class="size-3.5 text-zinc-500" />
                                <span class="text-xs font-medium text-zinc-500">Sem projeto</span>
                            </div>

                            <ul
                                wire:sort="handleSort"
                                wire:sort:group="tasks"
                                wire:sort:group-id="{{ $status->value }}"
                                class="flex flex-col gap-2"
                            >
                                @foreach ($unassignedTasks as $task)
                                    <li wire:key="task-{{ $task->id }}" wire:sort:item="{{ $task->id }}">
                                        <div
                                            class="group cursor-pointer rounded-lg border border-zinc-700/50 bg-zinc-800/50 p-3 transition hover:border-zinc-500"
                                            wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                        >
                                            {{-- Card Top: Handle + Title --}}
                                            <div class="flex items-start gap-2">
                                                <div wire:sort:handle class="mt-0.5 shrink-0 cursor-grab text-zinc-600 hover:text-zinc-400">
                                                    <flux:icon name="grip-vertical" class="size-4" />
                                                </div>
                                                <span class="line-clamp-2 flex-1 text-sm font-medium text-zinc-300">{{ $task->title }}</span>
                                            </div>

                                            {{-- Badges Row --}}
                                            <div class="mt-2 flex flex-wrap items-center gap-1.5" wire:sort:ignore>
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

                                                {{-- Commits Badge --}}
                                                @if ($task->commits_count > 0)
                                                    <flux:badge size="sm" color="zinc" icon="code-bracket">
                                                        {{ $task->commits_count }} {{ $task->commits_count === 1 ? 'commit' : 'commits' }}
                                                    </flux:badge>
                                                @endif

                                                {{-- Session Badge --}}
                                                @if ($task->isSessionTask())
                                                    <flux:badge size="sm" color="violet" class="gap-1">
                                                        🤖 Sessão
                                                    </flux:badge>
                                                @endif
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Load More Button --}}
                    @if ($hasMore)
                        <div class="mt-2 px-1">
                            <flux:button
                                wire:click="loadMore('{{ $status->value }}')"
                                wire:loading.attr="disabled"
                                wire:target="loadMore('{{ $status->value }}')"
                                variant="ghost"
                                size="sm"
                                class="w-full"
                            >
                                <span wire:loading.remove wire:target="loadMore('{{ $status->value }}')">
                                    Carregar mais ({{ $total - $limit }} restantes)
                                </span>
                                <span wire:loading wire:target="loadMore('{{ $status->value }}')">
                                    Carregando...
                                </span>
                            </flux:button>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
