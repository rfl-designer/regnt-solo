<?php

use App\Enums\FeaturePriority;
use App\Enums\FeatureStatus;
use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Feature;
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

    #[Url(as: 'view')]
    public string $viewMode = 'kanban';

    #[Url(as: 'sort')]
    public string $sortBy = 'priority';

    #[Url(as: 'dir')]
    public string $sortDirection = 'asc';

    #[Url(as: 'feature')]
    public ?int $drillFeatureId = null;

    /** @var array<string, int> */
    public array $limits = [
        'draft' => 20,
        'backlog' => 20,
        'todo' => 20,
        'doing' => 20,
        'done' => 20,
    ];

    /** @var array<string, int> */
    public array $drillLimits = [
        'backlog' => 20,
        'todo' => 20,
        'doing' => 20,
        'done' => 20,
    ];

    /** @var array<string, bool> */
    public array $expanded = [];

    /** @var list<FeatureStatus> */
    public array $kanbanStatuses = [];

    /** @var list<TaskStatus> */
    public array $drillTaskStatuses = [];

    public function mount(): void
    {
        $this->kanbanStatuses = [
            FeatureStatus::Backlog,
            FeatureStatus::Todo,
            FeatureStatus::Doing,
            FeatureStatus::Done,
        ];

        $this->drillTaskStatuses = [
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
     * Get all features with eager loading.
     *
     * @return \Illuminate\Support\Collection<int, Feature>
     */
    #[Computed]
    public function features(): \Illuminate\Support\Collection
    {
        $query = Feature::query()
            ->with(['project', 'tasks', 'timeEntries'])
            ->ordered();

        if ($this->filterProject !== '') {
            $query->where('project_id', (int) $this->filterProject);
        }

        if ($this->filterPriority !== '') {
            $query->where('priority', $this->filterPriority);
        }

        $features = $query->get();

        if ($this->filterOverdue) {
            $features = $features->filter(fn (Feature $f): bool => $f->due_date?->lt(today()) && $f->status !== FeatureStatus::Done);
        }

        return $features;
    }

    /**
     * Get features sorted for table view.
     *
     * @return \Illuminate\Support\Collection<int, Feature>
     */
    #[Computed]
    public function sortedFeatures(): \Illuminate\Support\Collection
    {
        $features = $this->features;

        $priorityOrder = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        return $features->sortBy(function (Feature $f) use ($priorityOrder) {
            return match ($this->sortBy) {
                'id' => $f->id,
                'title' => mb_strtolower($f->title),
                'status' => array_search($f->status->value, ['draft', 'backlog', 'todo', 'doing', 'done']),
                'priority' => $priorityOrder[$f->priority?->value ?? 'low'],
                'progress' => $f->progress,
                'time' => $f->total_time,
                'due_date' => $f->due_date?->timestamp ?? PHP_INT_MAX,
                default => $f->id,
            };
        }, descending: $this->sortDirection === 'desc')->values();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        unset($this->sortedFeatures);
    }

    /**
     * Get features for a specific column by computed status.
     *
     * @return \Illuminate\Support\Collection<int, Feature>
     */
    public function getColumnFeatures(FeatureStatus $status): \Illuminate\Support\Collection
    {
        return $this->features
            ->filter(fn (Feature $f): bool => $f->status === $status)
            ->take($this->limits[$status->value]);
    }

    /**
     * Get total count for a column.
     */
    public function getColumnTotal(FeatureStatus $status): int
    {
        return $this->features
            ->filter(fn (Feature $f): bool => $f->status === $status)
            ->count();
    }

    /**
     * Format minutes as human-readable duration.
     */
    public function formatDuration(float $minutes): string
    {
        if ($minutes === 0.0) {
            return '';
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

    public function loadMore(string $status): void
    {
        $this->limits[$status] += 20;
    }

    public function toggleExpanded(int $featureId): void
    {
        $key = (string) $featureId;
        $this->expanded[$key] = ! ($this->expanded[$key] ?? false);
    }

    public function isExpanded(int $featureId): bool
    {
        return $this->expanded[(string) $featureId] ?? false;
    }

    #[Computed]
    public function drillFeature(): ?Feature
    {
        if (! $this->drillFeatureId) {
            return null;
        }

        return Feature::with(['tasks.project', 'tasks.timeEntries', 'project'])->find($this->drillFeatureId);
    }

    public function enterDrill(int $featureId): void
    {
        $this->drillFeatureId = $featureId;
        $this->drillLimits = [
            'backlog' => 20,
            'todo' => 20,
            'doing' => 20,
            'done' => 20,
        ];

        unset($this->drillFeature);
    }

    public function exitDrill(): void
    {
        $this->drillFeatureId = null;

        unset($this->drillFeature);
    }

    /**
     * Get tasks for a drill-down column.
     *
     * @return \Illuminate\Support\Collection<int, Task>
     */
    public function getDrillColumnTasks(TaskStatus $status): \Illuminate\Support\Collection
    {
        $feature = $this->drillFeature;

        if (! $feature) {
            return collect();
        }

        $tasks = $feature->tasks
            ->filter(fn (Task $t): bool => $t->status === $status);

        if ($status === TaskStatus::Done) {
            $tasks = $tasks->filter(fn (Task $t): bool => $t->completed_at?->between(
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ));
        }

        return $tasks
            ->sortBy('sort_order')
            ->take($this->drillLimits[$status->value])
            ->values();
    }

    /**
     * Get total task count for a drill-down column.
     */
    public function getDrillColumnTotal(TaskStatus $status): int
    {
        $feature = $this->drillFeature;

        if (! $feature) {
            return 0;
        }

        $tasks = $feature->tasks
            ->filter(fn (Task $t): bool => $t->status === $status);

        if ($status === TaskStatus::Done) {
            $tasks = $tasks->filter(fn (Task $t): bool => $t->completed_at?->between(
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ));
        }

        return $tasks->count();
    }

    public function loadMoreDrill(string $status): void
    {
        $this->drillLimits[$status] += 20;
    }

    public function handleTaskSort(int|string $id, int $position, string $groupId): void
    {
        $task = Task::findOrFail((int) $id);
        $newStatus = TaskStatus::from($groupId);

        if (! $this->drillFeatureId || $task->feature_id !== $this->drillFeatureId) {
            return;
        }

        DB::transaction(function () use ($task, $newStatus, $position): void {
            if ($newStatus === TaskStatus::Done && $task->status !== TaskStatus::Done) {
                $dailyPlan = DailyPlan::getOrCreateForDate(Carbon::today());

                if (! $dailyPlan->tasks()->where('task_id', $task->id)->exists()) {
                    $maxOrder = $dailyPlan->tasks()->max('daily_plan_task.sort_order') ?? -1;
                    $dailyPlan->tasks()->attach($task->id, ['sort_order' => $maxOrder + 1]);
                }

                $task->markAsDone();

                Flux::toast(variant: 'success', heading: 'Task concluída', text: $task->title);
            } else {
                if ($task->status === TaskStatus::Done && $newStatus !== TaskStatus::Done) {
                    $task->update([
                        'status' => $newStatus,
                        'sort_order' => $position,
                        'completed_at' => null,
                    ]);
                } else {
                    $task->update([
                        'status' => $newStatus,
                        'sort_order' => $position,
                    ]);
                }
            }
        });

        unset($this->drillFeature);
        $this->dispatch('task-updated');
    }

    #[On('feature-updated')]
    #[On('feature-created')]
    #[On('task-updated')]
    #[On('task-created')]
    #[On('timer-updated')]
    public function refreshBoard(): void
    {
        unset($this->features);
        unset($this->sortedFeatures);
        unset($this->projects);
        unset($this->drillFeature);
    }

    public function startTimer(int $featureId): void
    {
        $feature = Feature::findOrFail($featureId);
        $feature->startTimer();

        Flux::toast(variant: 'success', heading: 'Timer iniciado', text: $feature->title);
        $this->dispatch('timer-updated');

        unset($this->features);
    }

    public function stopTimer(int $featureId): void
    {
        $feature = Feature::findOrFail($featureId);
        $runningEntry = $feature->runningEntry();

        if ($runningEntry) {
            $this->dispatch('open-timer-notes', entryId: $runningEntry->id);
        }
    }

    public function handleFeatureSort(int|string $id, int $position, string $groupId): void
    {
        $feature = Feature::findOrFail((int) $id);
        $feature->update(['status' => FeatureStatus::from($groupId), 'sort_order' => $position]);
        unset($this->features);
        $this->dispatch('feature-updated');
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col p-4 sm:p-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">Work</flux:heading>
            <flux:badge size="sm" color="zinc">{{ $this->features->count() }}</flux:badge>
        </div>

        <div class="flex items-center gap-3">
            {{-- Project filter --}}
            <flux:select wire:model.live="filterProject" size="sm" class="w-44">
                <option value="">Todos projetos</option>
                @foreach ($this->projects as $project)
                    <option value="{{ $project->id }}">{{ $project->emoji }} {{ $project->name }}</option>
                @endforeach
            </flux:select>

            {{-- Priority filter --}}
            <flux:select wire:model.live="filterPriority" size="sm" class="w-32">
                <option value="">Prioridades</option>
                @foreach (FeaturePriority::cases() as $priority)
                    <option value="{{ $priority->value }}">{{ $priority->label() }}</option>
                @endforeach
            </flux:select>

            {{-- Overdue toggle --}}
            <flux:button
                wire:click="$toggle('filterOverdue')"
                size="sm"
                :variant="$filterOverdue ? 'primary' : 'ghost'"
                icon="clock"
            >
                Vencidas
                @if ($filterOverdue)
                    ({{ $this->features->count() }})
                @endif
            </flux:button>

            {{-- View mode toggle --}}
            <flux:radio.group wire:model.live="viewMode" variant="segmented" size="sm">
                <flux:radio value="kanban" icon="view-columns" title="Kanban" />
                <flux:radio value="table" icon="list-bullet" title="Tabela" />
            </flux:radio.group>

            {{-- New Feature button --}}
            <flux:button
                wire:click="$dispatch('open-feature-modal')"
                size="sm"
                variant="primary"
                icon="plus"
            >
                Nova Feature
            </flux:button>
        </div>
    </div>

    {{-- Separator --}}
    <flux:separator class="my-4" />

    @if ($viewMode === 'kanban')

    @if ($drillFeatureId && $this->drillFeature)
    {{-- Drill-down Header --}}
    @php
        $df = $this->drillFeature;
        $dfTasksCount = $df->tasksCount();
        $dfCompletedCount = $df->completedTasksCount();
    @endphp
    <div
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="rounded-xl border border-zinc-700 bg-zinc-800/80 p-4 mb-4"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <flux:button
                    wire:click="exitDrill"
                    variant="ghost"
                    size="sm"
                    icon="arrow-left"
                >
                    Voltar para features
                </flux:button>

                <flux:separator vertical class="mx-1 h-6" />

                <div class="flex items-center gap-2">
                    @if ($df->project)
                        <span class="text-sm">{{ $df->project->emoji }}</span>
                    @endif
                    <flux:heading size="lg">{{ $df->title }}</flux:heading>
                </div>

                <flux:badge size="sm" color="{{ $df->status->color() }}">
                    {{ $df->status->label() }}
                </flux:badge>
            </div>

            <div class="flex items-center gap-3">
                @if ($df->project)
                    <span class="text-sm text-zinc-400">{{ $df->project->emoji }} {{ $df->project->name }}</span>
                    <flux:separator vertical class="mx-1 h-4" />
                @endif

                <div class="flex items-center gap-2">
                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-700">
                        <div
                            class="h-full rounded-full bg-{{ $df->status->color() }}-500 transition-all duration-300"
                            style="width: {{ $df->progress }}%"
                        ></div>
                    </div>
                    <span class="text-xs text-zinc-400">{{ $dfCompletedCount }}/{{ $dfTasksCount }} concluídas</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Drill-down: Task Kanban --}}
    <div
        x-data="{
            drillDoneCollapsed: localStorage.getItem('drill-done-collapsed') === 'true',
            toggleDrillDoneColumn() {
                this.drillDoneCollapsed = !this.drillDoneCollapsed;
                localStorage.setItem('drill-done-collapsed', this.drillDoneCollapsed);
            }
        }"
        class="-mx-4 flex flex-1 gap-3 overflow-x-auto px-4 pb-4 sm:mx-0 sm:gap-4 sm:px-0"
    >
        @foreach ($drillTaskStatuses as $status)
            @php
                $tasks = $this->getDrillColumnTasks($status);
                $total = $this->getDrillColumnTotal($status);
                $limit = $drillLimits[$status->value];
                $hasMore = $total > $limit;
                $isDone = $status === TaskStatus::Done;
            @endphp

            <div
                @if ($isDone)
                    x-bind:class="drillDoneCollapsed ? 'w-14' : 'w-72 sm:w-80'"
                @endif
                class="{{ $isDone ? '' : 'w-72 sm:w-80' }} flex shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50 transition-all duration-300 ease-in-out"
            >
                {{-- Column Header --}}
                @if ($isDone)
                    <div
                        @click="toggleDrillDoneColumn()"
                        x-bind:class="drillDoneCollapsed ? 'flex-col items-center py-4 cursor-pointer hover:bg-zinc-800/50' : 'flex-row items-center justify-between'"
                        class="flex border-b border-zinc-700 px-4 py-3 transition-all duration-200"
                    >
                        <template x-if="!drillDoneCollapsed">
                            <div class="flex w-full items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                    <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                                </div>
                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                    <button
                                        type="button"
                                        @click.stop="toggleDrillDoneColumn()"
                                        class="rounded p-1 text-zinc-500 transition hover:bg-zinc-700 hover:text-zinc-300"
                                        title="Colapsar coluna"
                                    >
                                        <flux:icon name="chevron-left" class="size-4" />
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template x-if="drillDoneCollapsed">
                            <div class="flex flex-col items-center gap-2">
                                <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                <flux:icon name="chevron-right" class="size-4 text-zinc-500" />
                            </div>
                        </template>
                    </div>
                @else
                    <div class="flex items-center justify-between border-b border-zinc-700 px-4 py-3">
                        <div class="flex items-center gap-2">
                            <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                            <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                        </div>
                        <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                    </div>
                @endif

                {{-- Tasks List --}}
                <div
                    @if ($isDone) x-show="!drillDoneCollapsed" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @endif
                    class="flex-1 overflow-y-auto p-2"
                >
                    <ul
                        wire:sort="handleTaskSort"
                        wire:sort:group="drill-tasks"
                        wire:sort:group-id="{{ $status->value }}"
                        class="kanban-dropzone flex min-h-[2rem] flex-col gap-2 rounded-lg transition-colors duration-200"
                    >
                        @forelse ($tasks as $task)
                            <li wire:key="drill-task-{{ $task->id }}" wire:sort:item="{{ $task->id }}" class="kanban-card">
                                <div
                                    class="group cursor-pointer rounded-lg border border-zinc-700 bg-zinc-800 p-3 transition-all duration-200 hover:border-zinc-500 hover:shadow-lg hover:shadow-zinc-900/50"
                                    wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                >
                                    {{-- Card Top: Handle + Title --}}
                                    <div class="flex items-start gap-2">
                                        <div wire:sort:handle class="mt-0.5 shrink-0 cursor-grab text-zinc-600 transition-colors hover:text-zinc-300 active:cursor-grabbing">
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

                                        @if ($task->isSessionTask())
                                            <flux:badge size="sm" color="violet" class="gap-1">
                                                🤖 Sessão
                                            </flux:badge>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @empty
                            <li class="py-8 text-center text-sm text-zinc-600">
                                Nenhuma task
                            </li>
                        @endforelse
                    </ul>

                    {{-- Load More Button --}}
                    @if ($hasMore)
                        <div class="mt-2 px-1">
                            <flux:button
                                wire:click="loadMoreDrill('{{ $status->value }}')"
                                wire:loading.attr="disabled"
                                wire:target="loadMoreDrill('{{ $status->value }}')"
                                variant="ghost"
                                size="sm"
                                class="w-full"
                            >
                                <span wire:loading.remove wire:target="loadMoreDrill('{{ $status->value }}')">
                                    Carregar mais ({{ $total - $limit }} restantes)
                                </span>
                                <span wire:loading wire:target="loadMoreDrill('{{ $status->value }}')">
                                    Carregando...
                                </span>
                            </flux:button>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @else
    {{-- Normal: Feature Kanban Board --}}
    <div
        x-data="{
            doneCollapsed: localStorage.getItem('features-done-collapsed') === 'true',
            toggleDoneColumn() {
                this.doneCollapsed = !this.doneCollapsed;
                localStorage.setItem('features-done-collapsed', this.doneCollapsed);
            }
        }"
        class="-mx-4 flex flex-1 gap-3 overflow-x-auto px-4 pb-4 sm:mx-0 sm:gap-4 sm:px-0"
    >
        @foreach ($kanbanStatuses as $status)
            @php
                $features = $this->getColumnFeatures($status);
                $total = $this->getColumnTotal($status);
                $limit = $limits[$status->value];
                $hasMore = $total > $limit;
                $isDone = $status === FeatureStatus::Done;
            @endphp

            <div
                @if ($isDone)
                    x-bind:class="doneCollapsed ? 'w-14' : 'w-80 sm:w-96'"
                @endif
                class="{{ $isDone ? '' : 'w-80 sm:w-96' }} flex shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50 transition-all duration-300 ease-in-out"
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
                                    <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                    <button
                                        type="button"
                                        @click.stop="toggleDoneColumn()"
                                        class="rounded p-1 text-zinc-500 transition hover:bg-zinc-700 hover:text-zinc-300"
                                        title="Colapsar coluna"
                                    >
                                        <flux:icon name="chevron-left" class="size-4" />
                                    </button>
                                </div>
                            </div>
                        </template>

                        {{-- Collapsed state --}}
                        <template x-if="doneCollapsed">
                            <div class="flex flex-col items-center gap-2">
                                <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                <flux:icon name="chevron-right" class="size-4 text-zinc-500" />
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
                            <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                            <flux:button
                                wire:click="$dispatch('open-feature-modal')"
                                variant="ghost"
                                size="sm"
                                icon="plus"
                                class="ml-1 !p-1"
                                title="Nova feature"
                            />
                        </div>
                    </div>
                @endif

                {{-- Features List --}}
                <div
                    @if ($isDone) x-show="!doneCollapsed" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @endif
                    class="flex-1 space-y-3 overflow-y-auto p-3"
                >
                    <ul
                        wire:sort="handleFeatureSort"
                        wire:sort:group="features"
                        wire:sort:group-id="{{ $status->value }}"
                        class="flex min-h-[2rem] flex-col gap-3"
                    >
                        @forelse ($features as $feature)
                            <li wire:key="feature-{{ $feature->id }}" wire:sort:item="{{ $feature->id }}" wire:sort:handle>
                                <x-feature-card
                                    :feature="$feature"
                                    :expanded="$this->isExpanded($feature->id)"
                                    :show-project="true"
                                />
                            </li>
                        @empty
                            <li class="py-8 text-center text-sm text-zinc-600">
                                Nenhuma feature
                            </li>
                        @endforelse
                    </ul>

                    {{-- Load More Button --}}
                    @if ($hasMore)
                        <div class="mt-2">
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
    @endif
    @else
    {{-- Table/List View --}}
    <div class="flex-1 overflow-auto rounded-xl border border-zinc-700 bg-zinc-900/50">
        @if ($this->sortedFeatures->isEmpty())
            <div class="flex flex-col items-center justify-center py-16">
                <flux:icon name="list-bullet" class="mb-3 size-12 text-zinc-600" />
                <flux:heading size="sm" class="text-zinc-400">Nenhuma feature encontrada</flux:heading>
                <flux:text size="sm" class="mt-1 text-zinc-500">Crie uma nova feature para começar</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'id'" :direction="$sortDirection" wire:click="sort('id')">ID</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'title'" :direction="$sortDirection" wire:click="sort('title')">Título</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">Status</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'priority'" :direction="$sortDirection" wire:click="sort('priority')">Prioridade</flux:table.column>
                    <flux:table.column>Projeto</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'progress'" :direction="$sortDirection" wire:click="sort('progress')">Progresso</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'time'" :direction="$sortDirection" wire:click="sort('time')" align="end">Tempo</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'due_date'" :direction="$sortDirection" wire:click="sort('due_date')" align="end">Vencimento</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->sortedFeatures as $feature)
                        @php
                            $tasksCount = $feature->tasksCount();
                            $completedCount = $feature->completedTasksCount();
                            $totalTime = $feature->total_time;
                            $isOverdue = $feature->due_date?->isPast() && $feature->status !== FeatureStatus::Done;
                        @endphp

                        <flux:table.row
                            :key="$feature->id"
                            wire:click="$dispatch('open-feature-modal', { featureId: {{ $feature->id }} })"
                            class="cursor-pointer hover:bg-zinc-800/50"
                        >
                            {{-- ID --}}
                            <flux:table.cell class="whitespace-nowrap text-zinc-500">
                                #F-{{ $feature->id }}
                            </flux:table.cell>

                            {{-- Título --}}
                            <flux:table.cell variant="strong" class="max-w-xs truncate">
                                {{ $feature->title }}
                            </flux:table.cell>

                            {{-- Status --}}
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$feature->status->color()" inset="top bottom">
                                    {{ $feature->status->label() }}
                                </flux:badge>
                            </flux:table.cell>

                            {{-- Prioridade --}}
                            <flux:table.cell>
                                @if ($feature->priority)
                                    <flux:badge size="sm" :color="$feature->priority->color()" :icon="$feature->priority->icon()" inset="top bottom">
                                        {{ $feature->priority->label() }}
                                    </flux:badge>
                                @else
                                    <span class="text-zinc-600">—</span>
                                @endif
                            </flux:table.cell>

                            {{-- Projeto --}}
                            <flux:table.cell>
                                @if ($feature->project)
                                    <span class="whitespace-nowrap text-sm">
                                        {{ $feature->project->emoji }} {{ $feature->project->name }}
                                    </span>
                                @else
                                    <span class="text-zinc-600">—</span>
                                @endif
                            </flux:table.cell>

                            {{-- Progresso --}}
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-20 overflow-hidden rounded-full bg-zinc-700">
                                        <div
                                            class="h-full rounded-full bg-{{ $feature->status->color() }}-500"
                                            style="width: {{ $feature->progress }}%"
                                        ></div>
                                    </div>
                                    <span class="whitespace-nowrap text-xs text-zinc-400">{{ $completedCount }}/{{ $tasksCount }}</span>
                                </div>
                            </flux:table.cell>

                            {{-- Tempo Total --}}
                            <flux:table.cell align="end" class="whitespace-nowrap text-zinc-400">
                                @if ($totalTime > 0)
                                    {{ $this->formatDuration($totalTime) }}
                                @else
                                    <span class="text-zinc-600">—</span>
                                @endif
                            </flux:table.cell>

                            {{-- Due Date --}}
                            <flux:table.cell align="end" class="whitespace-nowrap">
                                @if ($feature->due_date)
                                    <span class="{{ $isOverdue ? 'text-red-400' : 'text-zinc-400' }}">
                                        {{ $feature->due_date->format('d/m/Y') }}
                                    </span>
                                @else
                                    <span class="text-zinc-600">—</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
    @endif

</div>
