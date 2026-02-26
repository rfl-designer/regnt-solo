<?php

use App\Enums\FeatureStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Document;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public string $slug = '';

    public string $tab = 'board';

    public bool $showArchiveModal = false;

    #[Url(as: 'feature')]
    public ?int $drillFeatureId = null;

    /** @var list<FeatureStatus> */
    public array $kanbanStatuses = [];

    /** @var array<string, int> */
    public array $limits = [
        'draft' => 10,
        'backlog' => 10,
        'todo' => 10,
        'doing' => 10,
        'done' => 10,
    ];

    /** @var array<string, int> */
    public array $drillLimits = [
        'backlog' => 20,
        'todo' => 20,
        'doing' => 20,
        'done' => 20,
    ];

    public ?int $selectedDocumentId = null;

    /** @var array<string, bool> */
    public array $expanded = [];

    /** @var list<TaskStatus> */
    public array $drillTaskStatuses = [];

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $this->kanbanStatuses = [
            FeatureStatus::Draft,
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

    #[Computed]
    public function project(): Project
    {
        return Project::query()
            ->where('slug', $this->slug)
            ->with(['tasks.timeEntries', 'tasks.commits', 'documents'])
            ->firstOrFail();
    }

    /**
     * Get all features for this project with eager loading.
     *
     * @return \Illuminate\Support\Collection<int, Feature>
     */
    #[Computed]
    public function features(): \Illuminate\Support\Collection
    {
        return Feature::query()
            ->forProject($this->project->id)
            ->with(['tasks', 'timeEntries'])
            ->ordered()
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Document>
     */
    #[Computed]
    public function projectDocuments(): \Illuminate\Support\Collection
    {
        return $this->project->documents
            ->sortBy([
                ['is_pinned', 'desc'],
                ['sort_order', 'asc'],
                ['title', 'asc'],
            ]);
    }

    #[Computed]
    public function selectedDocument(): ?Document
    {
        if (! $this->selectedDocumentId) {
            return null;
        }

        return $this->projectDocuments->firstWhere('id', $this->selectedDocumentId);
    }

    public function selectDocument(int $documentId): void
    {
        $this->selectedDocumentId = $documentId;
    }

    /**
     * Group features by their computed status.
     *
     * @return array<string, \Illuminate\Support\Collection<int, Feature>>
     */
    #[Computed]
    public function featuresByStatus(): array
    {
        $grouped = [];

        foreach (FeatureStatus::cases() as $status) {
            $grouped[$status->value] = $this->features
                ->filter(fn (Feature $f): bool => $f->status === $status)
                ->values();
        }

        return $grouped;
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

    public function loadMore(string $status): void
    {
        $this->limits[$status] += 10;
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

        $featureCount = $this->features->count();
        $doneFeatures = $this->features->filter(fn (Feature $f): bool => $f->status === FeatureStatus::Done)->count();

        $featuresByStatus = [];
        foreach (FeatureStatus::cases() as $featureStatus) {
            $featuresByStatus[$featureStatus->value] = $this->features
                ->filter(fn (Feature $f): bool => $f->status === $featureStatus)
                ->count();
        }

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'total_hours' => round($totalMinutes / 60, 1),
            'completion_percent' => $total > 0 ? round(($doneCount / $total) * 100, 1) : 0,
            'total_commits' => $totalCommits,
            'total_files_changed' => $totalFilesChanged,
            'feature_count' => $featureCount,
            'done_features' => $doneFeatures,
            'feature_completion_percent' => $featureCount > 0 ? round(($doneFeatures / $featureCount) * 100, 1) : 0,
            'features_by_status' => $featuresByStatus,
        ];
    }

    public function archiveProject(): void
    {
        $project = $this->project;
        $project->update(['status' => ProjectStatus::Archived]);

        $this->showArchiveModal = false;

        unset($this->project, $this->features, $this->metrics);

        $this->dispatch('project-updated');

        Flux::toast(variant: 'success', heading: 'Projeto arquivado', text: $project->name);
    }

    public function activateProject(): void
    {
        $project = $this->project;
        $project->update(['status' => ProjectStatus::Active]);

        unset($this->project, $this->features, $this->metrics);

        $this->dispatch('project-updated');

        Flux::toast(variant: 'success', heading: 'Projeto reativado', text: $project->name);
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

    #[On('feature-created')]
    #[On('feature-updated')]
    #[On('task-created')]
    #[On('task-updated')]
    #[On('timer-updated')]
    #[On('project-updated')]
    #[On('document-saved')]
    public function refreshProject(): void
    {
        unset($this->project, $this->features, $this->featuresByStatus, $this->metrics, $this->projectDocuments, $this->selectedDocument, $this->drillFeature);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    {{-- Breadcrumb --}}
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('projects') }}" wire:navigate icon="folder">
            Projetos
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>
            {{ $this->project->emoji }} {{ $this->project->name }}
        </flux:breadcrumbs.item>
    </flux:breadcrumbs>

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

            <flux:button
                variant="ghost"
                icon="user-group"
                wire:click="$dispatch('open-stakeholders-modal', { projectId: {{ $this->project->id }} })"
            >
                Stakeholders
            </flux:button>

            {{-- Separador visual --}}
            <flux:separator vertical class="mx-1 h-6" />

            @if ($this->project->status !== ProjectStatus::Archived)
                <flux:button
                    variant="ghost"
                    icon="archive-box"
                    class="text-red-400 hover:bg-red-500/10 hover:text-red-300"
                    wire:click="$set('showArchiveModal', true)"
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
            <flux:tab name="board" icon="view-columns">Board</flux:tab>
            <flux:tab name="features" icon="rectangle-stack">Features</flux:tab>
            <flux:tab name="docs" icon="document-text">Docs</flux:tab>
            <flux:tab name="metrics" icon="chart-bar-square">Métricas</flux:tab>
        </flux:tabs>

        {{-- Tab Panel: Board (Feature Kanban) --}}
        <flux:tab.panel name="board">
            <div class="flex flex-col gap-4">
                @if ($drillFeatureId && $this->drillFeature)
                {{-- Drill-down: Task Kanban --}}
                <div class="flex items-center justify-between">
                    <flux:badge size="sm" color="zinc">{{ $this->drillFeature->tasks->count() }} tasks</flux:badge>
                </div>

                <div
                    x-data="{
                        drillDoneCollapsed: localStorage.getItem('project-drill-done-collapsed') === 'true',
                        toggleDrillDoneColumn() {
                            this.drillDoneCollapsed = !this.drillDoneCollapsed;
                            localStorage.setItem('project-drill-done-collapsed', this.drillDoneCollapsed);
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
                <div class="flex items-center justify-between">
                    <flux:badge size="sm" color="zinc">{{ $this->features->count() }} features</flux:badge>
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="plus"
                        wire:click="$dispatch('open-feature-modal')"
                    >
                        Nova Feature
                    </flux:button>
                </div>

                <div
                    x-data="{
                        doneCollapsed: localStorage.getItem('project-feature-done-collapsed') === 'true',
                        toggleDoneColumn() {
                            this.doneCollapsed = !this.doneCollapsed;
                            localStorage.setItem('project-feature-done-collapsed', this.doneCollapsed);
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
                                x-bind:class="doneCollapsed ? 'w-14' : 'w-72 sm:w-80'"
                            @endif
                            class="{{ $isDone ? '' : 'w-72 sm:w-80' }} flex shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50 transition-all duration-300 ease-in-out"
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
                                @forelse ($features as $feature)
                                    <x-feature-card
                                        :feature="$feature"
                                        :expanded="$this->isExpanded($feature->id)"
                                        :show-project="false"
                                    />
                                @empty
                                    <div class="py-8 text-center text-sm text-zinc-600">
                                        Nenhuma feature
                                    </div>
                                @endforelse

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
            </div>
        </flux:tab.panel>

        {{-- Tab Panel: Features (Specs) --}}
        <flux:tab.panel name="features">
            @if ($tab === 'features')
                <div class="flex flex-col gap-4">
                    {{-- Header --}}
                    <div class="flex items-center justify-between">
                        <flux:badge size="sm" color="zinc">{{ $this->features->count() }} features</flux:badge>
                        <flux:button
                            size="sm"
                            variant="primary"
                            icon="plus"
                            wire:click="$dispatch('open-feature-modal')"
                        >
                            Nova Feature
                        </flux:button>
                    </div>

                    @if ($this->features->isEmpty())
                        {{-- Empty State --}}
                        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-16">
                            <flux:icon name="rectangle-stack" class="mb-3 size-12 text-zinc-600" />
                            <flux:heading size="sm" class="text-zinc-400">Nenhuma feature neste projeto</flux:heading>
                            <flux:text class="mt-1 text-sm text-zinc-500">Crie uma feature para começar a planejar.</flux:text>
                            <flux:button
                                size="sm"
                                variant="primary"
                                icon="plus"
                                class="mt-4"
                                wire:click="$dispatch('open-feature-modal')"
                            >
                                Nova Feature
                            </flux:button>
                        </div>
                    @else
                        {{-- Feature Spec Cards --}}
                        <div class="space-y-6">
                            @foreach ($this->features as $feature)
                                @php
                                    $tasksCount = $feature->tasksCount();
                                    $completedCount = $feature->completedTasksCount();
                                @endphp

                                <div
                                    wire:key="feature-spec-{{ $feature->id }}"
                                    class="rounded-xl border border-zinc-700 bg-zinc-900/50"
                                >
                                    {{-- Feature Header --}}
                                    <div class="flex items-start justify-between border-b border-zinc-700 p-4">
                                        <div class="min-w-0 flex-1">
                                            {{-- Title (clickable) --}}
                                            <button
                                                type="button"
                                                wire:click="$dispatch('open-feature-modal', { featureId: {{ $feature->id }} })"
                                                class="text-left text-lg font-semibold text-zinc-100 transition hover:text-white"
                                            >
                                                {{ $feature->title }}
                                            </button>

                                            {{-- Badges --}}
                                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                                {{-- Status Badge --}}
                                                <flux:badge size="sm" color="{{ $feature->status->color() }}" icon="{{ $feature->status->icon() }}">
                                                    {{ $feature->status->label() }}
                                                </flux:badge>

                                                {{-- Progress --}}
                                                @if ($tasksCount > 0)
                                                    <flux:badge size="sm" color="zinc">
                                                        {{ $completedCount }}/{{ $tasksCount }} tasks
                                                    </flux:badge>
                                                @endif

                                                {{-- Priority --}}
                                                @if ($feature->priority)
                                                    <flux:badge size="sm" color="{{ $feature->priority->color() }}" icon="{{ $feature->priority->icon() }}">
                                                        {{ $feature->priority->label() }}
                                                    </flux:badge>
                                                @endif

                                                {{-- Due Date --}}
                                                @if ($feature->due_date)
                                                    @php
                                                        $isOverdue = $feature->due_date->isPast();
                                                    @endphp
                                                    <flux:badge size="sm" color="{{ $isOverdue ? 'red' : 'zinc' }}" icon="{{ $isOverdue ? 'exclamation-triangle' : 'calendar' }}">
                                                        {{ $feature->due_date->format('d/m/Y') }}
                                                    </flux:badge>
                                                @endif
                                            </div>

                                            {{-- Progress Bar --}}
                                            @if ($tasksCount > 0)
                                                <div class="mt-3 max-w-md">
                                                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-700">
                                                        <div
                                                            class="h-full rounded-full bg-{{ $feature->status->color() }}-500 transition-all duration-300"
                                                            style="width: {{ $feature->progress }}%"
                                                        ></div>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Edit Button --}}
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="pencil-square"
                                            wire:click="$dispatch('open-feature-modal', { featureId: {{ $feature->id }} })"
                                            title="Editar feature"
                                            class="shrink-0"
                                        />
                                    </div>

                                    {{-- Spec Content --}}
                                    @if ($feature->spec)
                                        <div class="p-4">
                                            <livewire:markdown-viewer
                                                :content="$feature->spec"
                                                :show-copy-buttons="false"
                                                :key="'feature-spec-'.$feature->id"
                                            />
                                        </div>
                                    @else
                                        <div class="p-4 text-center text-sm text-zinc-500">
                                            Nenhuma spec definida para esta feature.
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
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
                    {{-- Desktop: Split View (lg+) --}}
                    <div class="hidden lg:flex lg:gap-4" style="min-height: 500px;">
                        {{-- Left Panel: Document List --}}
                        <div class="flex w-1/3 flex-col gap-2 overflow-y-auto border-r border-zinc-700 pr-4">
                            @foreach ($this->projectDocuments as $document)
                                <div
                                    wire:key="doc-list-{{ $document->id }}"
                                    wire:click="selectDocument({{ $document->id }})"
                                    class="cursor-pointer rounded-lg border p-3 transition {{ $selectedDocumentId === $document->id ? 'border-indigo-500 bg-indigo-500/10' : 'border-zinc-700 bg-zinc-900/50 hover:border-zinc-500' }}"
                                >
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-{{ $document->type->color() }}-500/10">
                                            <flux:icon :name="$document->type->icon()" class="size-4 text-{{ $document->type->color() }}-400" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-medium text-zinc-200">{{ $document->title }}</span>
                                                @if ($document->is_pinned)
                                                    <flux:icon name="star" variant="micro" class="size-3 text-amber-400" />
                                                @endif
                                            </div>
                                            <div class="mt-1 flex items-center gap-2">
                                                <flux:badge size="sm" :color="$document->type->color()">
                                                    {{ $document->type->label() }}
                                                </flux:badge>
                                                <span class="text-xs text-zinc-500">{{ $document->updated_at->diffForHumans() }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Right Panel: Preview Area --}}
                        <div class="w-2/3 overflow-y-auto">
                            @if ($this->selectedDocument)
                                <div class="flex flex-col gap-4">
                                    {{-- Document Header --}}
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-{{ $this->selectedDocument->type->color() }}-500/10">
                                                <flux:icon :name="$this->selectedDocument->type->icon()" class="size-5 text-{{ $this->selectedDocument->type->color() }}-400" />
                                            </div>
                                            <div>
                                                <flux:heading size="lg">{{ $this->selectedDocument->title }}</flux:heading>
                                                <div class="flex items-center gap-2">
                                                    <flux:badge size="sm" :color="$this->selectedDocument->type->color()">
                                                        {{ $this->selectedDocument->type->label() }}
                                                    </flux:badge>
                                                    @if ($this->selectedDocument->is_pinned)
                                                        <flux:badge size="sm" color="amber" icon="star">Fixado</flux:badge>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="arrow-top-right-on-square"
                                                href="{{ route('document.view', $this->selectedDocument->slug) }}"
                                                wire:navigate
                                            >
                                                Abrir
                                            </flux:button>
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="pencil-square"
                                                href="{{ route('document.edit', $this->selectedDocument->slug) }}"
                                                wire:navigate
                                            >
                                                Editar
                                            </flux:button>
                                        </div>
                                    </div>

                                    {{-- Markdown Preview --}}
                                    <div class="rounded-lg border border-zinc-700 bg-zinc-900/30 p-4">
                                        <livewire:markdown-viewer
                                            :content="$this->selectedDocument->content"
                                            :show-copy-buttons="false"
                                            :key="'preview-'.$this->selectedDocument->id"
                                        />
                                    </div>
                                </div>
                            @else
                                {{-- Empty State --}}
                                <div class="flex h-full flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-16">
                                    <flux:icon name="document-magnifying-glass" class="mb-3 size-12 text-zinc-600" />
                                    <flux:heading size="sm" class="text-zinc-400">Selecione um documento</flux:heading>
                                    <flux:text class="mt-1 text-sm text-zinc-500">Clique em um documento na lista para visualizar o preview.</flux:text>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Mobile/Tablet: List with Modal Preview --}}
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden">
                        @foreach ($this->projectDocuments as $document)
                            <div
                                wire:key="doc-mobile-{{ $document->id }}"
                                class="flex items-start gap-3 rounded-lg border border-zinc-700 bg-zinc-900/50 p-3 transition hover:border-zinc-500"
                            >
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-{{ $document->type->color() }}-500/10">
                                    <flux:icon :name="$document->type->icon()" class="size-4 text-{{ $document->type->color() }}-400" />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-zinc-200">{{ $document->title }}</span>
                                        @if ($document->is_pinned)
                                            <flux:icon name="star" variant="micro" class="size-3 text-amber-400" />
                                        @endif
                                    </div>
                                    <div class="mt-1 flex items-center gap-2">
                                        <flux:badge size="sm" :color="$document->type->color()">
                                            {{ $document->type->label() }}
                                        </flux:badge>
                                    </div>
                                    <flux:text class="mt-1 line-clamp-2 text-xs text-zinc-500">
                                        {{ $document->excerpt(100) }}
                                    </flux:text>
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        icon="eye"
                                        wire:click="selectDocument({{ $document->id }})"
                                        x-on:click="$flux.modal('doc-preview-modal').show()"
                                    />
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        icon="pencil-square"
                                        href="{{ route('document.edit', $document->slug) }}"
                                        wire:navigate
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Mobile Preview Modal (flyout) --}}
                    <flux:modal name="doc-preview-modal" variant="flyout" class="w-full max-w-2xl">
                        @if ($this->selectedDocument)
                            <div class="flex flex-col gap-4">
                                {{-- Header --}}
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-{{ $this->selectedDocument->type->color() }}-500/10">
                                            <flux:icon :name="$this->selectedDocument->type->icon()" class="size-5 text-{{ $this->selectedDocument->type->color() }}-400" />
                                        </div>
                                        <div>
                                            <flux:heading size="lg">{{ $this->selectedDocument->title }}</flux:heading>
                                            <div class="flex items-center gap-2">
                                                <flux:badge size="sm" :color="$this->selectedDocument->type->color()">
                                                    {{ $this->selectedDocument->type->label() }}
                                                </flux:badge>
                                                @if ($this->selectedDocument->is_pinned)
                                                    <flux:badge size="sm" color="amber" icon="star">Fixado</flux:badge>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="pencil-square"
                                        href="{{ route('document.edit', $this->selectedDocument->slug) }}"
                                        wire:navigate
                                    />
                                </div>

                                {{-- Markdown Preview --}}
                                <div class="overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-900/30 p-4">
                                    <livewire:markdown-viewer
                                        :content="$this->selectedDocument->content"
                                        :show-copy-buttons="false"
                                        :key="'preview-mobile-'.$this->selectedDocument->id"
                                    />
                                </div>

                                {{-- Actions --}}
                                <div class="flex justify-end gap-2">
                                    <flux:button
                                        variant="ghost"
                                        icon="arrow-top-right-on-square"
                                        href="{{ route('document.view', $this->selectedDocument->slug) }}"
                                        wire:navigate
                                    >
                                        Abrir Documento
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    </flux:modal>
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

                {{-- Total de Features --}}
                <flux:card class="space-y-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="squares-2x2" class="size-5 text-indigo-400" />
                        <flux:text class="text-sm text-zinc-400">Features</flux:text>
                    </div>
                    <flux:heading size="xl">{{ $metrics['feature_count'] }}</flux:heading>
                </flux:card>

                {{-- Conclusão de Features --}}
                <flux:card class="space-y-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="flag" class="size-5 text-emerald-400" />
                        <flux:text class="text-sm text-zinc-400">Features Concluídas</flux:text>
                    </div>
                    <flux:heading size="xl">{{ $metrics['done_features'] }}/{{ $metrics['feature_count'] }}</flux:heading>
                    @if ($metrics['feature_count'] > 0)
                        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-700">
                            <div
                                class="h-full rounded-full bg-emerald-500 transition-all duration-500"
                                style="width: {{ $metrics['feature_completion_percent'] }}%"
                            ></div>
                        </div>
                    @endif
                </flux:card>
            </div>

            {{-- Features por Status --}}
            <div class="mt-6">
                <flux:heading size="sm" class="mb-3">Features por Status</flux:heading>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach (FeatureStatus::cases() as $featureStatus)
                        @php
                            $fCount = $metrics['features_by_status'][$featureStatus->value] ?? 0;
                        @endphp

                        <div class="flex items-center gap-3 rounded-lg border border-zinc-700 bg-zinc-900/50 px-4 py-3">
                            <flux:icon :name="$featureStatus->icon()" class="size-5 text-{{ $featureStatus->color() }}-400" />
                            <div class="flex flex-1 items-center justify-between">
                                <span class="text-sm text-zinc-300">{{ $featureStatus->label() }}</span>
                                <flux:badge size="sm" color="{{ $featureStatus->color() }}">{{ $fCount }}</flux:badge>
                            </div>
                        </div>
                    @endforeach
                </div>
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

    {{-- Stakeholders Modal --}}
    <livewire:project-stakeholders-modal />

    {{-- Archive Project Modal --}}
    <flux:modal wire:model.self="showArchiveModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <div class="mb-3 flex items-center gap-2 text-red-400">
                    <flux:icon name="archive-box" class="size-5" />
                    <flux:heading size="lg">Arquivar projeto</flux:heading>
                </div>

                <flux:text class="text-zinc-400">
                    Tem certeza que deseja arquivar <strong class="text-zinc-200">{{ $this->project->name }}</strong>?
                </flux:text>

                <div class="mt-3 rounded-lg border border-zinc-700 bg-zinc-800/50 p-3">
                    <flux:text class="text-sm text-zinc-400">
                        <ul class="list-inside list-disc space-y-1">
                            <li>O projeto não aparecerá na lista de projetos ativos</li>
                            <li>As tasks associadas continuarão vinculadas</li>
                            <li>Você pode reativar o projeto a qualquer momento</li>
                        </ul>
                    </flux:text>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="danger"
                    icon="archive-box"
                    wire:click="archiveProject"
                    wire:loading.attr="disabled"
                    wire:target="archiveProject"
                >
                    <span wire:loading.remove wire:target="archiveProject">Arquivar projeto</span>
                    <span wire:loading wire:target="archiveProject">Arquivando...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
