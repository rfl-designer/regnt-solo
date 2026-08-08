<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\ProjectStatus;
use App\Enums\StakeholderIssueStatus;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Project;
use App\Models\StakeholderIssue;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
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

    /** @var list<ActivityStatus> */
    public array $kanbanStatuses = [];

    /** @var array<string, int> */
    public array $limits = [
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

    public ?int $selectedFeatureId = null;

    /** @var array<string, bool> */
    public array $expanded = [];

    /** @var list<ActivityStatus> */
    public array $drillTaskStatuses = [];

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $this->kanbanStatuses = [
            ActivityStatus::Backlog,
            ActivityStatus::Todo,
            ActivityStatus::Doing,
            ActivityStatus::Done,
        ];

        $this->drillTaskStatuses = [
            ActivityStatus::Backlog,
            ActivityStatus::Todo,
            ActivityStatus::Doing,
            ActivityStatus::Done,
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
     * @return \Illuminate\Support\Collection<int, Activity>
     */
    #[Computed]
    public function features(): \Illuminate\Support\Collection
    {
        return Activity::query()
            ->epics()
            ->forProject($this->project->id)
            ->with(['children', 'timeEntries'])
            ->ordered()
            ->get();
    }

    /**
     * Personal tasks (type=Task) attached to this project.
     *
     * @return \Illuminate\Support\Collection<int, Activity>
     */
    #[Computed]
    public function projectTasks(): \Illuminate\Support\Collection
    {
        return Activity::query()
            ->tasks()
            ->forProject($this->project->id)
            ->with('parent')
            ->ordered()
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, StakeholderIssue>
     */
    #[Computed]
    public function stakeholderIssues(): \Illuminate\Support\Collection
    {
        return StakeholderIssue::query()
            ->where('project_id', $this->project->id)
            ->with(['stakeholder', 'activity'])
            ->latest('created_at')
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

    #[Computed]
    public function selectedFeature(): ?Activity
    {
        if (! $this->selectedFeatureId) {
            return null;
        }

        return $this->features->firstWhere('id', $this->selectedFeatureId);
    }

    public function selectFeature(int $featureId): void
    {
        if (! $this->features->contains('id', $featureId)) {
            return;
        }

        $this->selectedFeatureId = $featureId;
    }

    /**
     * Group features by their computed status.
     *
     * @return array<string, \Illuminate\Support\Collection<int, Activity>>
     */
    #[Computed]
    public function featuresByStatus(): array
    {
        $grouped = [];

        foreach (ActivityStatus::cases() as $status) {
            $grouped[$status->value] = $this->features
                ->filter(fn (Activity $f): bool => $f->status === $status)
                ->values();
        }

        return $grouped;
    }

    /**
     * Get features for a specific column by computed status.
     *
     * @return \Illuminate\Support\Collection<int, Activity>
     */
    public function getColumnFeatures(ActivityStatus $status): \Illuminate\Support\Collection
    {
        return $this->features
            ->filter(fn (Activity $f): bool => $f->status === $status)
            ->take($this->limits[$status->value]);
    }

    /**
     * Get total count for a column.
     */
    public function getColumnTotal(ActivityStatus $status): int
    {
        return $this->features
            ->filter(fn (Activity $f): bool => $f->status === $status)
            ->count();
    }

    public function loadMore(string $status): void
    {
        $this->limits[$status] += 10;
    }

    public function markIssueAsToFeature(int $issueId): void
    {
        $issue = StakeholderIssue::query()
            ->where('project_id', $this->project->id)
            ->findOrFail($issueId);

        if ($issue->status === StakeholderIssueStatus::ToFeature) {
            return;
        }

        $issue->update([
            'status' => StakeholderIssueStatus::ToFeature,
        ]);

        unset($this->stakeholderIssues);

        Flux::toast(variant: 'success', heading: 'Issue atualizada', text: 'Issue marcada para feature.');
    }

    public function archiveIssue(int $issueId): void
    {
        $issue = StakeholderIssue::query()
            ->where('project_id', $this->project->id)
            ->findOrFail($issueId);

        if ($issue->status === StakeholderIssueStatus::Archived) {
            return;
        }

        $issue->update([
            'status' => StakeholderIssueStatus::Archived,
        ]);

        unset($this->stakeholderIssues);

        Flux::toast(variant: 'success', heading: 'Issue arquivada', text: 'A issue foi movida para arquivadas.');
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
        $feature = Activity::findOrFail($featureId);
        $feature->startTimer();

        Flux::toast(variant: 'success', heading: 'Timer iniciado', text: $feature->title);
        $this->dispatch('timer-updated');

        unset($this->features);
    }

    public function stopTimer(int $featureId): void
    {
        $feature = Activity::findOrFail($featureId);
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
        $doneCount = $tasks->where('status', ActivityStatus::Done)->count();

        $byStatus = [];
        foreach (ActivityStatus::cases() as $status) {
            $byStatus[$status->value] = $tasks->where('status', $status)->count();
        }

        $totalMinutes = $tasks->flatMap->timeEntries->sum(function ($entry) {
            $end = $entry->stopped_at ?? now();

            return $entry->started_at->diffInMinutes($end);
        });

        $totalCommits = $tasks->sum(fn ($task) => $task->commits->count());
        $totalFilesChanged = $tasks->sum(fn ($task) => $task->commits->sum('files_changed'));

        $featureCount = $this->features->count();
        $doneFeatures = $this->features->filter(fn (Activity $f): bool => $f->status === ActivityStatus::Done)->count();

        $featuresByStatus = [];
        foreach (ActivityStatus::cases() as $featureStatus) {
            $featuresByStatus[$featureStatus->value] = $this->features
                ->filter(fn (Activity $f): bool => $f->status === $featureStatus)
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
    public function drillFeature(): ?Activity
    {
        if (! $this->drillFeatureId) {
            return null;
        }

        return Activity::with(['children.project', 'children.parent', 'children.timeEntries', 'project'])->find($this->drillFeatureId);
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
     * @return \Illuminate\Support\Collection<int, Activity>
     */
    public function getDrillColumnTasks(ActivityStatus $status): \Illuminate\Support\Collection
    {
        $feature = $this->drillFeature;

        if (! $feature) {
            return collect();
        }

        $tasks = $feature->children
            ->filter(fn (Activity $t): bool => $t->type === ActivityType::Issue && $t->status === $status);

        if ($status === ActivityStatus::Done) {
            $tasks = $tasks->filter(fn (Activity $t): bool => ! $t->isArchived());
        }

        return $tasks
            ->sortBy('sort_order')
            ->take($this->drillLimits[$status->value])
            ->values();
    }

    /**
     * Get total task count for a drill-down column.
     */
    public function getDrillColumnTotal(ActivityStatus $status): int
    {
        $feature = $this->drillFeature;

        if (! $feature) {
            return 0;
        }

        $tasks = $feature->children
            ->filter(fn (Activity $t): bool => $t->type === ActivityType::Issue && $t->status === $status);

        if ($status === ActivityStatus::Done) {
            $tasks = $tasks->filter(fn (Activity $t): bool => ! $t->isArchived());
        }

        return $tasks->count();
    }

    public function loadMoreDrill(string $status): void
    {
        $this->drillLimits[$status] += 20;
    }

    public function handleTaskSort(int|string $id, int $position, string $groupId): void
    {
        $task = Activity::findOrFail((int) $id);
        $newStatus = ActivityStatus::from($groupId);

        if (! $this->drillFeatureId || $task->parent_id !== $this->drillFeatureId) {
            return;
        }

        DB::transaction(function () use ($task, $newStatus, $position): void {
            if ($newStatus === ActivityStatus::Done && $task->status !== ActivityStatus::Done) {
                $task->markAsDone();

                Flux::toast(variant: 'success', heading: 'Task concluída', text: $task->title);
            } else {
                if ($task->status === ActivityStatus::Done && $newStatus !== ActivityStatus::Done) {
                    $task->update([
                        'status' => $newStatus,
                        'completed_at' => null,
                    ]);
                } else {
                    $task->update(['status' => $newStatus]);
                }
            }

            $this->reorderDrillColumn($newStatus, $task->id, $position);
        });

        unset($this->drillFeature);
        $this->dispatch('task-updated');
    }

    /**
     * Insert the moved task at the given position within the drill-down
     * column and renumber the column's tasks to unique sort_order values.
     */
    private function reorderDrillColumn(ActivityStatus $status, int $movedId, int $position): void
    {
        $query = Activity::query()
            ->where('parent_id', $this->drillFeatureId)
            ->where('status', $status)
            ->where('id', '!=', $movedId);

        if ($status === ActivityStatus::Done) {
            $query->notArchived();
        }

        $ids = $query->orderBy('sort_order')->orderBy('id')->pluck('id')->all();

        $position = max(0, min($position, count($ids)));
        array_splice($ids, $position, 0, [$movedId]);

        foreach ($ids as $index => $id) {
            Activity::query()->where('id', $id)->update(['sort_order' => $index]);
        }
    }

    public function handleFeatureSort(int|string $id, int $position, string $groupId): void
    {
        $feature = Activity::query()
            ->epics()
            ->forProject($this->project->id)
            ->findOrFail((int) $id);
        $newStatus = ActivityStatus::from($groupId);

        DB::transaction(function () use ($feature, $newStatus, $position): void {
            $feature->update(['status' => $newStatus]);
            $this->reorderFeatureColumn($newStatus, $feature->id, $position);
        });

        unset($this->features);
        $this->dispatch('feature-updated');
    }

    /**
     * Insert the moved feature at the given position within its column and
     * renumber the project's features to unique sort_order values.
     */
    private function reorderFeatureColumn(ActivityStatus $status, int $movedId, int $position): void
    {
        $ids = Activity::query()
            ->epics()
            ->forProject($this->project->id)
            ->where('status', $status)
            ->where('id', '!=', $movedId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $position = max(0, min($position, count($ids)));
        array_splice($ids, $position, 0, [$movedId]);

        foreach ($ids as $index => $id) {
            Activity::query()->where('id', $id)->update(['sort_order' => $index]);
        }
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
        unset($this->project, $this->features, $this->featuresByStatus, $this->metrics, $this->projectDocuments, $this->selectedDocument, $this->selectedFeature, $this->drillFeature, $this->stakeholderIssues, $this->projectTasks);
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
            <flux:tab name="issues" icon="chat-bubble-left-right">Issues ({{ $this->stakeholderIssues->count() }})</flux:tab>
            <flux:tab name="docs" icon="document-text">Docs</flux:tab>
            <flux:tab name="metrics" icon="chart-bar-square">Métricas</flux:tab>
        </flux:tabs>

        {{-- Tab Panel: Board (Feature Kanban) --}}
        <flux:tab.panel name="board">
            <div class="flex flex-col gap-4">
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
                    class="rounded-xl border border-zinc-700 bg-zinc-800/80 p-4"
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

                {{-- Drill-down: Task Kanban --}}
                <div
                    x-data="{
                        collapsed: JSON.parse(localStorage.getItem('project-drill-collapsed') || '{}'),
                        toggleColumn(status) {
                            this.collapsed[status] = ! this.collapsed[status];
                            localStorage.setItem('project-drill-collapsed', JSON.stringify(this.collapsed));
                        },
                        isCollapsed(status) {
                            return !! this.collapsed[status];
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
                        @endphp

                        <div
                            x-bind:class="isCollapsed('{{ $status->value }}') ? 'w-14' : 'w-72 sm:w-80'"
                            class="flex shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50 transition-all duration-300 ease-in-out"
                        >
                            {{-- Column Header --}}
                            <div
                                @click="toggleColumn('{{ $status->value }}')"
                                x-bind:class="isCollapsed('{{ $status->value }}') ? 'flex-col items-center py-4' : 'flex-row items-center justify-between'"
                                class="flex cursor-pointer border-b border-zinc-700 px-4 py-3 transition-all duration-200 hover:bg-zinc-800/50"
                            >
                                <template x-if="!isCollapsed('{{ $status->value }}')">
                                    <div class="flex w-full items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                            <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                            <button
                                                type="button"
                                                @click.stop="toggleColumn('{{ $status->value }}')"
                                                class="rounded p-1 text-zinc-500 transition hover:bg-zinc-700 hover:text-zinc-300"
                                                title="Colapsar coluna"
                                            >
                                                <flux:icon name="chevron-left" class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="isCollapsed('{{ $status->value }}')">
                                    <div class="flex flex-col items-center gap-2">
                                        <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                        <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                        <flux:icon name="chevron-right" class="size-4 text-zinc-500" />
                                    </div>
                                </template>
                            </div>

                            {{-- Tasks List --}}
                            <div
                                x-show="!isCollapsed('{{ $status->value }}')"
                                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
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
                                                    <flux:badge size="sm" color="{{ $task->type->color() }}" icon="{{ $task->type->icon() }}">
                                                        {{ $task->derivedLabel() }}
                                                    </flux:badge>

                                                    @if ($task->service_class)
                                                        <flux:badge size="sm" color="{{ $task->service_class->color() }}" icon="{{ $task->service_class->icon() }}">
                                                            {{ $task->service_class->label() }}
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
                {{-- Normal: Feature Kanban Board + Tasks side panel --}}
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
                <div class="flex min-w-0 flex-1 flex-col gap-4">
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
                        collapsed: JSON.parse(localStorage.getItem('project-feature-collapsed') || '{}'),
                        toggleColumn(status) {
                            this.collapsed[status] = ! this.collapsed[status];
                            localStorage.setItem('project-feature-collapsed', JSON.stringify(this.collapsed));
                        },
                        isCollapsed(status) {
                            return !! this.collapsed[status];
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
                        @endphp

                        <div
                            x-bind:class="isCollapsed('{{ $status->value }}') ? 'w-14' : 'w-72 sm:w-80'"
                            class="flex shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50 transition-all duration-300 ease-in-out"
                        >
                            {{-- Column Header --}}
                            <div
                                @click="toggleColumn('{{ $status->value }}')"
                                x-bind:class="isCollapsed('{{ $status->value }}') ? 'flex-col items-center py-4' : 'flex-row items-center justify-between'"
                                class="flex cursor-pointer border-b border-zinc-700 px-4 py-3 transition-all duration-200 hover:bg-zinc-800/50"
                            >
                                {{-- Expanded state --}}
                                <template x-if="!isCollapsed('{{ $status->value }}')">
                                    <div class="flex w-full items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                            <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                            <flux:button
                                                wire:click="$dispatch('open-feature-modal')"
                                                @click.stop
                                                variant="ghost"
                                                size="sm"
                                                icon="plus"
                                                class="ml-1 !p-1"
                                                title="Nova feature"
                                            />
                                            <button
                                                type="button"
                                                @click.stop="toggleColumn('{{ $status->value }}')"
                                                class="rounded p-1 text-zinc-500 transition hover:bg-zinc-700 hover:text-zinc-300"
                                                title="Colapsar coluna"
                                            >
                                                <flux:icon name="chevron-left" class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                </template>

                                {{-- Collapsed state --}}
                                <template x-if="isCollapsed('{{ $status->value }}')">
                                    <div class="flex flex-col items-center gap-2">
                                        <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                        <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                        <flux:icon name="chevron-right" class="size-4 text-zinc-500" />
                                    </div>
                                </template>
                            </div>

                            {{-- Features List --}}
                            <div
                                x-show="!isCollapsed('{{ $status->value }}')"
                                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                                class="flex-1 space-y-3 overflow-y-auto p-3"
                            >
                                <ul
                                    wire:sort="handleFeatureSort"
                                    wire:sort:group="features"
                                    wire:sort:group-id="{{ $status->value }}"
                                    class="flex min-h-[2rem] flex-col gap-3"
                                >
                                    @forelse ($features as $feature)
                                        <li wire:key="feature-{{ $feature->id }}" wire:sort:item="{{ $feature->id }}">
                                            <x-feature-card
                                                :feature="$feature"
                                                :expanded="$this->isExpanded($feature->id)"
                                                :show-project="false"
                                                :sortable="true"
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
                </div>

                {{-- Tasks side panel --}}
                <aside class="flex w-full shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50 lg:w-72">
                    <div class="flex items-center justify-between border-b border-zinc-700 px-4 py-3">
                        <div class="flex items-center gap-2">
                            <flux:icon name="check-circle" class="size-5 text-emerald-400" />
                            <flux:heading size="sm">Tarefas</flux:heading>
                            <flux:badge size="sm" color="zinc">{{ $this->projectTasks->count() }}</flux:badge>
                        </div>
                        <flux:button
                            wire:click="$dispatch('open-quick-add-with-project', { projectId: {{ $this->project->id }} })"
                            variant="ghost"
                            size="sm"
                            icon="plus"
                            class="!p-1"
                            title="Nova task"
                        />
                    </div>

                    <div class="flex flex-col gap-2 overflow-y-auto p-2">
                        @forelse ($this->projectTasks as $task)
                            <button
                                type="button"
                                wire:key="project-task-{{ $task->id }}"
                                wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                class="group flex flex-col gap-1.5 rounded-lg border border-zinc-700 bg-zinc-800 p-3 text-left transition hover:border-zinc-500"
                            >
                                <span class="line-clamp-2 text-sm font-medium text-zinc-200 {{ $task->status === ActivityStatus::Done ? 'text-zinc-500 line-through' : '' }}">
                                    {{ $task->title }}
                                </span>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <flux:badge size="sm" color="{{ $task->status->color() }}" icon="{{ $task->status->icon() }}">
                                        {{ $task->status->label() }}
                                    </flux:badge>
                                    @if ($task->service_class)
                                        <flux:badge size="sm" color="{{ $task->service_class->color() }}" icon="{{ $task->service_class->icon() }}">
                                            {{ $task->service_class->label() }}
                                        </flux:badge>
                                    @endif
                                    @if ($task->isOverdue())
                                        <flux:badge size="sm" color="red" icon="exclamation-triangle">
                                            {{ $task->due_date->diffForHumans() }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </button>
                        @empty
                            <div class="px-2 py-8 text-center">
                                <flux:icon name="check-circle" class="mx-auto mb-2 size-8 text-zinc-600" />
                                <flux:text class="text-xs text-zinc-500">Nenhuma task neste projeto.</flux:text>
                            </div>
                        @endforelse
                    </div>
                </aside>
                </div>
                @endif
            </div>
        </flux:tab.panel>

        {{-- Tab Panel: Features (Specs) --}}
        <flux:tab.panel name="features">
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
                    {{-- Desktop: Split View (lg+) --}}
                    <div class="hidden lg:flex lg:gap-4" style="min-height: 500px;">
                        {{-- Left Panel: Feature List --}}
                        <div class="flex w-1/3 flex-col gap-2 overflow-y-auto border-r border-zinc-700 pr-4">
                            @foreach ($this->features as $feature)
                                @php
                                    $tasksCount = $feature->tasksCount();
                                    $completedCount = $feature->completedTasksCount();
                                    $isOverdue = $feature->due_date?->isPast() ?? false;
                                @endphp

                                <div
                                    wire:key="feature-list-{{ $feature->id }}"
                                    wire:click="selectFeature({{ $feature->id }})"
                                    class="cursor-pointer rounded-lg border p-3 transition {{ $selectedFeatureId === $feature->id ? 'border-indigo-500 bg-indigo-500/10' : 'border-zinc-700 bg-zinc-900/50 hover:border-zinc-500' }}"
                                >
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-{{ $feature->status->color() }}-500/10">
                                            <flux:icon :name="$feature->status->icon()" class="size-4 text-{{ $feature->status->color() }}-400" />
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-medium text-zinc-200">{{ $feature->title }}</span>
                                                <span class="text-xs font-mono text-zinc-500">#F-{{ $feature->id }}</span>
                                            </div>

                                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                                <flux:badge size="sm" color="{{ $feature->status->color() }}" icon="{{ $feature->status->icon() }}">
                                                    {{ $feature->status->label() }}
                                                </flux:badge>

                                                @if ($tasksCount > 0)
                                                    <flux:badge size="sm" color="zinc">
                                                        {{ $completedCount }}/{{ $tasksCount }} tasks
                                                    </flux:badge>
                                                @endif

                                                @if ($feature->priority)
                                                    <flux:badge size="sm" color="{{ $feature->priority->color() }}" icon="{{ $feature->priority->icon() }}">
                                                        {{ $feature->priority->label() }}
                                                    </flux:badge>
                                                @endif

                                                @if ($feature->due_date)
                                                    <flux:badge size="sm" color="{{ $isOverdue ? 'red' : 'zinc' }}" icon="{{ $isOverdue ? 'exclamation-triangle' : 'calendar' }}">
                                                        {{ $feature->due_date->format('d/m/Y') }}
                                                    </flux:badge>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Right Panel: Spec Preview --}}
                        <div class="w-2/3 overflow-y-auto">
                            @if ($this->selectedFeature)
                                @if ($this->selectedFeature->spec)
                                    <div class="rounded-lg border border-zinc-700 bg-zinc-900/30 p-4">
                                        <livewire:markdown-viewer
                                            :content="$this->selectedFeature->spec"
                                            :show-copy-buttons="false"
                                            :key="'feature-preview-'.$this->selectedFeature->id"
                                        />
                                    </div>
                                @else
                                    <div class="flex h-full flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-16">
                                        <flux:icon name="document-magnifying-glass" class="mb-3 size-12 text-zinc-600" />
                                        <flux:heading size="sm" class="text-zinc-400">Nenhuma spec definida para esta feature.</flux:heading>
                                    </div>
                                @endif
                            @else
                                <div class="flex h-full flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-16">
                                    <flux:icon name="document-magnifying-glass" class="mb-3 size-12 text-zinc-600" />
                                    <flux:heading size="sm" class="text-zinc-400">Selecione uma feature</flux:heading>
                                    <flux:text class="mt-1 text-sm text-zinc-500">Clique em uma feature na lista para visualizar o preview da spec.</flux:text>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Mobile/Tablet: List with Modal Preview --}}
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden">
                        @foreach ($this->features as $feature)
                            @php
                                $tasksCount = $feature->tasksCount();
                                $completedCount = $feature->completedTasksCount();
                                $isOverdue = $feature->due_date?->isPast() ?? false;
                                $specPreview = trim(\Illuminate\Support\Str::limit(strip_tags($feature->spec ?? ''), 120));
                            @endphp

                            <div
                                wire:key="feature-mobile-{{ $feature->id }}"
                                wire:click="selectFeature({{ $feature->id }})"
                                x-on:click="$flux.modal('feature-spec-preview-modal').show()"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-700 bg-zinc-900/50 p-3 transition hover:border-zinc-500"
                            >
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-{{ $feature->status->color() }}-500/10">
                                    <flux:icon :name="$feature->status->icon()" class="size-4 text-{{ $feature->status->color() }}-400" />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-zinc-200">{{ $feature->title }}</span>
                                        <span class="text-xs font-mono text-zinc-500">#F-{{ $feature->id }}</span>
                                    </div>

                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                        <flux:badge size="sm" color="{{ $feature->status->color() }}" icon="{{ $feature->status->icon() }}">
                                            {{ $feature->status->label() }}
                                        </flux:badge>

                                        @if ($tasksCount > 0)
                                            <flux:badge size="sm" color="zinc">
                                                {{ $completedCount }}/{{ $tasksCount }} tasks
                                            </flux:badge>
                                        @endif

                                        @if ($feature->due_date)
                                            <flux:badge size="sm" color="{{ $isOverdue ? 'red' : 'zinc' }}" icon="{{ $isOverdue ? 'exclamation-triangle' : 'calendar' }}">
                                                {{ $feature->due_date->format('d/m/Y') }}
                                            </flux:badge>
                                        @endif
                                    </div>

                                    <flux:text class="mt-1 line-clamp-2 text-xs text-zinc-500">
                                        {{ $specPreview !== '' ? $specPreview : 'Sem spec definida.' }}
                                    </flux:text>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Mobile Preview Modal (flyout) --}}
                    <flux:modal name="feature-spec-preview-modal" variant="flyout" class="w-full max-w-2xl">
                        @if ($this->selectedFeature)
                            @if ($this->selectedFeature->spec)
                                <div class="overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-900/30 p-4">
                                    <livewire:markdown-viewer
                                        :content="$this->selectedFeature->spec"
                                        :show-copy-buttons="false"
                                        :key="'feature-preview-mobile-'.$this->selectedFeature->id"
                                    />
                                </div>
                            @else
                                <div class="py-8 text-center text-sm text-zinc-500">
                                    Nenhuma spec definida para esta feature.
                                </div>
                            @endif
                        @endif
                    </flux:modal>
                @endif
            </div>
        </flux:tab.panel>

        {{-- Tab Panel: Issues --}}
        <flux:tab.panel name="issues">
            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <flux:badge size="sm" color="zinc">{{ $this->stakeholderIssues->count() }} issues</flux:badge>
                </div>

                @if ($this->stakeholderIssues->isEmpty())
                    <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-16">
                        <flux:icon name="chat-bubble-left-right" class="mb-3 size-12 text-zinc-600" />
                        <flux:heading size="sm" class="text-zinc-400">Nenhuma issue de stakeholder neste projeto</flux:heading>
                        <flux:text class="mt-1 text-sm text-zinc-500">Quando stakeholders enviarem feedback, as issues aparecerão aqui.</flux:text>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($this->stakeholderIssues as $issue)
                            <div wire:key="project-issue-{{ $issue->id }}" class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0 space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <flux:badge size="sm" color="{{ $issue->status->color() }}" icon="{{ $issue->status->icon() }}">
                                                {{ $issue->status->label() }}
                                            </flux:badge>

                                            <span class="text-xs text-zinc-500">#I-{{ $issue->id }}</span>
                                            <span class="text-xs text-zinc-500">{{ $issue->created_at->format('d/m/Y H:i') }}</span>
                                        </div>

                                        <p class="text-sm leading-relaxed whitespace-pre-wrap text-zinc-200">{{ $issue->comment }}</p>

                                        <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                                            <span>{{ $issue->stakeholder?->name ?? 'Stakeholder' }}</span>
                                            <span>•</span>
                                            <span>{{ $issue->stakeholder?->email ?? 'Sem e-mail' }}</span>
                                        </div>

                                        @if ($issue->activity)
                                            <div class="flex items-center gap-2 text-xs">
                                                <flux:icon name="rectangle-stack" class="size-4 text-emerald-400" />
                                                <span class="text-zinc-400">Feature vinculada:</span>
                                                <span class="font-medium text-zinc-200">#F-{{ $issue->activity->id }} {{ $issue->activity->title }}</span>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($issue->status !== StakeholderIssueStatus::ToFeature && $issue->status !== StakeholderIssueStatus::Feature)
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="light-bulb"
                                                wire:click="markIssueAsToFeature({{ $issue->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="markIssueAsToFeature({{ $issue->id }})"
                                            >
                                                Para feature
                                            </flux:button>
                                        @endif

                                        @if ($issue->status !== StakeholderIssueStatus::Archived)
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                icon="archive-box"
                                                class="text-zinc-300 hover:text-zinc-100"
                                                wire:click="archiveIssue({{ $issue->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="archiveIssue({{ $issue->id }})"
                                            >
                                                Arquivar
                                            </flux:button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
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
                                                <span class="text-xs font-mono text-zinc-500">#D-{{ $document->id }}</span>
                                                @if ($document->is_pinned)
                                                    <flux:icon name="star" variant="micro" class="size-3 text-amber-400" />
                                                @endif
                                            </div>
                                            <div class="mt-1 flex items-center gap-2">
                                                <flux:badge size="sm" :color="$document->type->color()">
                                                    {{ $document->type->label() }}
                                                </flux:badge>
                                                @if ($document->is_context)
                                                    <flux:badge size="sm" color="violet" icon="cpu-chip">Contexto</flux:badge>
                                                @endif
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
                                                <div class="flex items-center gap-2">
                                                    <flux:heading size="lg">{{ $this->selectedDocument->title }}</flux:heading>
                                                    <span class="text-xs font-mono text-zinc-500">#D-{{ $this->selectedDocument->id }}</span>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <flux:badge size="sm" :color="$this->selectedDocument->type->color()">
                                                        {{ $this->selectedDocument->type->label() }}</flux:badge>
                                                    @if ($this->selectedDocument->is_pinned)
                                                        <flux:badge size="sm" color="amber" icon="star">Fixado</flux:badge>
                                                    @endif
                                                    @if ($this->selectedDocument->is_context)
                                                        <flux:badge size="sm" color="violet" icon="cpu-chip">Contexto</flux:badge>
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
                                        <span class="text-xs font-mono text-zinc-500">#D-{{ $document->id }}</span>
                                        @if ($document->is_pinned)
                                            <flux:icon name="star" variant="micro" class="size-3 text-amber-400" />
                                        @endif
                                    </div>
                                    <div class="mt-1 flex items-center gap-2">
                                        <flux:badge size="sm" :color="$document->type->color()">
                                            {{ $document->type->label() }}
                                        </flux:badge>
                                        @if ($document->is_context)
                                            <flux:badge size="sm" color="violet" icon="cpu-chip">Contexto</flux:badge>
                                        @endif
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
                                            <div class="flex items-center gap-2">
                                                <flux:heading size="lg">{{ $this->selectedDocument->title }}</flux:heading>
                                                <span class="text-xs font-mono text-zinc-500">#D-{{ $this->selectedDocument->id }}</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <flux:badge size="sm" :color="$this->selectedDocument->type->color()">
                                                    {{ $this->selectedDocument->type->label() }}
                                                </flux:badge>
                                                @if ($this->selectedDocument->is_pinned)
                                                    <flux:badge size="sm" color="amber" icon="star">Fixado</flux:badge>
                                                @endif
                                                @if ($this->selectedDocument->is_context)
                                                    <flux:badge size="sm" color="violet" icon="cpu-chip">Contexto</flux:badge>
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
                    @foreach (ActivityStatus::cases() as $featureStatus)
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
                    @foreach (ActivityStatus::cases() as $status)
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
