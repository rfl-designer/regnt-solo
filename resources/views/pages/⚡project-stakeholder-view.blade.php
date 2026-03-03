<?php

use App\Enums\FeatureStatus;
use App\Enums\StakeholderIssueStatus;
use App\Enums\TaskStatus;
use App\Models\Document;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\StakeholderIssue;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Acompanhamento de Projeto')] class extends Component
{
    public string $token = '';

    public string $tab = 'board';

    /** @var list<FeatureStatus> */
    public array $featureStatuses = [];

    /** @var list<TaskStatus> */
    public array $drillTaskStatuses = [];

    /** @var array<string, int> */
    public array $featureLimits = [
        'backlog' => 10,
        'todo' => 10,
        'doing' => 10,
        'done' => 10,
    ];

    /** @var array<string, int> */
    public array $drillLimits = [
        'backlog' => 10,
        'todo' => 10,
        'doing' => 10,
        'done' => 10,
    ];

    public ?int $drillFeatureId = null;

    public ?int $selectedFeatureId = null;

    public ?int $selectedDocumentId = null;

    public string $mobileFeatureStatus = 'doing';

    public string $mobileTaskStatus = 'doing';

    public string $newIssueComment = '';

    public bool $showIssuesPanel = true;

    public function mount(string $token): void
    {
        $this->token = $token;

        $stakeholder = Stakeholder::query()->where('access_token', $token)->first();

        if (! $stakeholder) {
            abort(404);
        }

        $stakeholder->update(['last_accessed_at' => now()]);

        $this->featureStatuses = [
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
    public function stakeholder(): Stakeholder
    {
        return Stakeholder::query()
            ->where('access_token', $this->token)
            ->with('project')
            ->firstOrFail();
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()
            ->where('id', $this->stakeholder->project_id)
            ->with(['tasks.timeEntries', 'tasks.commits', 'documents'])
            ->firstOrFail();
    }

    /**
     * @return \Illuminate\Support\Collection<int, StakeholderIssue>
     */
    #[Computed]
    public function stakeholderIssues(): \Illuminate\Support\Collection
    {
        return StakeholderIssue::query()
            ->where('stakeholder_id', $this->stakeholder->id)
            ->with(['feature', 'stakeholder'])
            ->latest('created_at')
            ->get();
    }

    /**
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

    #[Computed]
    public function selectedFeature(): ?Feature
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

    #[Computed]
    public function drillFeature(): ?Feature
    {
        if (! $this->drillFeatureId) {
            return null;
        }

        return $this->features->firstWhere('id', $this->drillFeatureId);
    }

    public function enterDrill(int $featureId): void
    {
        if (! $this->features->contains('id', $featureId)) {
            return;
        }

        $this->drillFeatureId = $featureId;
        $this->drillLimits = [
            'backlog' => 10,
            'todo' => 10,
            'doing' => 10,
            'done' => 10,
        ];
    }

    public function exitDrill(): void
    {
        $this->drillFeatureId = null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Feature>
     */
    public function getColumnFeatures(FeatureStatus $status): \Illuminate\Support\Collection
    {
        return $this->features
            ->filter(fn (Feature $feature): bool => $feature->status === $status)
            ->take($this->featureLimits[$status->value])
            ->values();
    }

    public function getColumnTotal(FeatureStatus $status): int
    {
        return $this->features
            ->filter(fn (Feature $feature): bool => $feature->status === $status)
            ->count();
    }

    public function loadMoreFeatures(string $status): void
    {
        if (! array_key_exists($status, $this->featureLimits)) {
            return;
        }

        $this->featureLimits[$status] += 10;
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Task>
     */
    public function getDrillColumnTasks(TaskStatus $status): \Illuminate\Support\Collection
    {
        $feature = $this->drillFeature;

        if (! $feature) {
            return collect();
        }

        $tasks = $feature->tasks
            ->filter(fn ($task): bool => $task->status === $status);

        if ($status === TaskStatus::Done) {
            $tasks = $tasks->filter(fn ($task): bool => $task->completed_at?->between(
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ));
        }

        return $tasks
            ->sortBy([
                ['sort_order', 'asc'],
                ['created_at', 'desc'],
            ])
            ->take($this->drillLimits[$status->value])
            ->values();
    }

    public function getDrillColumnTotal(TaskStatus $status): int
    {
        $feature = $this->drillFeature;

        if (! $feature) {
            return 0;
        }

        $tasks = $feature->tasks
            ->filter(fn ($task): bool => $task->status === $status);

        if ($status === TaskStatus::Done) {
            $tasks = $tasks->filter(fn ($task): bool => $task->completed_at?->between(
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ));
        }

        return $tasks->count();
    }

    public function loadMoreDrill(string $status): void
    {
        if (! array_key_exists($status, $this->drillLimits)) {
            return;
        }

        $this->drillLimits[$status] += 10;
    }

    public function addIssue(): void
    {
        $validated = $this->validate([
            'newIssueComment' => ['required', 'string', 'min:5', 'max:4000'],
        ], [
            'newIssueComment.required' => 'Escreva um comentário para abrir a issue.',
            'newIssueComment.min' => 'A issue precisa ter pelo menos 5 caracteres.',
        ]);

        StakeholderIssue::query()->create([
            'stakeholder_id' => $this->stakeholder->id,
            'project_id' => $this->project->id,
            'comment' => $validated['newIssueComment'],
            'status' => StakeholderIssueStatus::Unread,
        ]);

        $this->newIssueComment = '';
    }

    public function toggleIssuesPanel(): void
    {
        $this->showIssuesPanel = ! $this->showIssuesPanel;
    }

    /**
     * @return array{total: int, by_status: array<string, int>, total_hours: float, completion_percent: float, total_commits: int, total_files_changed: int, feature_count: int, done_features: int, feature_completion_percent: float, features_by_status: array<string, int>}
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
        $doneFeatures = $this->features->filter(fn (Feature $feature): bool => $feature->status === FeatureStatus::Done)->count();

        $featuresByStatus = [];
        foreach (FeatureStatus::cases() as $featureStatus) {
            $featuresByStatus[$featureStatus->value] = $this->features
                ->filter(fn (Feature $feature): bool => $feature->status === $featureStatus)
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
}

?>

<div class="flex min-h-screen w-full flex-col bg-zinc-950">
    <div class="border-b border-zinc-800 bg-violet-500/10 px-6 py-3">
        <div class="flex w-full items-center justify-between">
            <div class="flex items-center gap-2">
                <flux:icon name="eye" class="size-4 text-violet-400" />
                <span class="text-sm text-violet-300">Visualização de Stakeholder</span>
            </div>
            <flux:badge size="sm" color="violet" icon="chat-bubble-left-right">
                Leitura + feedback
            </flux:badge>
        </div>
    </div>

    <div class="flex w-full flex-1 flex-col gap-6 p-6 xl:flex-row xl:items-start">
        <div class="flex min-w-0 flex-1 flex-col gap-6">
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

            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 text-sm text-zinc-500">
                    <flux:icon name="user" class="size-4" />
                    <span>{{ $this->stakeholder->name }}</span>
                </div>

                <flux:button
                    wire:click="toggleIssuesPanel"
                    variant="ghost"
                    size="sm"
                    icon="{{ $showIssuesPanel ? 'eye-slash' : 'eye' }}"
                >
                    {{ $showIssuesPanel ? 'Esconder issues' : 'Mostrar issues' }}
                </flux:button>
            </div>
        </div>

        @if ($this->project->description)
            <flux:text class="text-sm text-zinc-400">{{ $this->project->description }}</flux:text>
        @endif

        <flux:tab.group>
            <flux:tabs wire:model="tab">
                <flux:tab name="board" icon="view-columns">Board</flux:tab>
                <flux:tab name="features" icon="rectangle-stack">Features</flux:tab>
                <flux:tab name="docs" icon="document-text">Docs</flux:tab>
                <flux:tab name="metrics" icon="chart-bar-square">Métricas</flux:tab>
            </flux:tabs>

            <flux:tab.panel name="board">
                <div class="flex flex-col gap-4">
                    @if ($drillFeatureId && $this->drillFeature)
                        @php
                            $drillFeature = $this->drillFeature;
                            $drillTasksCount = $drillFeature->tasksCount();
                            $drillCompletedCount = $drillFeature->completedTasksCount();
                        @endphp

                        <div class="rounded-xl border border-zinc-700 bg-zinc-800/80 p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-3">
                                    <flux:button wire:click="exitDrill" variant="ghost" size="sm" icon="arrow-left">
                                        Voltar para board
                                    </flux:button>

                                    <flux:separator vertical class="mx-1 h-6" />

                                    <div class="flex items-center gap-2">
                                        <flux:heading size="lg">{{ $drillFeature->title }}</flux:heading>
                                        <flux:badge size="sm" color="{{ $drillFeature->status->color() }}">
                                            {{ $drillFeature->status->label() }}
                                        </flux:badge>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-700">
                                        <div
                                            class="h-full rounded-full bg-{{ $drillFeature->status->color() }}-500 transition-all duration-300"
                                            style="width: {{ $drillFeature->progress }}%"
                                        ></div>
                                    </div>
                                    <span class="text-xs text-zinc-400">{{ $drillCompletedCount }}/{{ $drillTasksCount }} concluídas</span>
                                </div>
                            </div>
                        </div>

                        <div class="md:hidden">
                            <flux:select wire:model.live="mobileTaskStatus" class="mb-4">
                                @foreach ($drillTaskStatuses as $status)
                                    <flux:select.option value="{{ $status->value }}">
                                        {{ $status->label() }} ({{ $this->getDrillColumnTotal($status) }})
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            @php
                                $mobileTaskStatusEnum = TaskStatus::from($mobileTaskStatus);
                                $tasks = $this->getDrillColumnTasks($mobileTaskStatusEnum);
                                $total = $this->getDrillColumnTotal($mobileTaskStatusEnum);
                                $limit = $drillLimits[$mobileTaskStatus];
                                $hasMore = $total > $limit;
                            @endphp

                            <div class="flex flex-col rounded-xl border border-zinc-700 bg-zinc-900/50">
                                <div class="flex items-center justify-between border-b border-zinc-700 px-3 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <flux:icon :name="$mobileTaskStatusEnum->icon()" class="size-4 text-{{ $mobileTaskStatusEnum->color() }}-400" />
                                        <span class="text-sm font-medium text-zinc-300">{{ $mobileTaskStatusEnum->label() }}</span>
                                    </div>
                                    <flux:badge size="sm" color="{{ $mobileTaskStatusEnum->color() }}">{{ $total }}</flux:badge>
                                </div>

                                <div class="p-2">
                                    @forelse ($tasks as $task)
                                        <div
                                            wire:key="mobile-drill-task-{{ $task->id }}"
                                            class="mb-2 rounded-lg border border-zinc-700 bg-zinc-800 p-2.5"
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
                                            </div>
                                        </div>
                                    @empty
                                        <div class="py-6 text-center text-xs text-zinc-600">Nenhuma task</div>
                                    @endforelse

                                    @if ($hasMore)
                                        <div class="mt-2">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                class="w-full"
                                                wire:click="loadMoreDrill('{{ $mobileTaskStatus }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="loadMoreDrill('{{ $mobileTaskStatus }}')"
                                            >
                                                <span wire:loading.remove wire:target="loadMoreDrill('{{ $mobileTaskStatus }}')">
                                                    Carregar mais ({{ $total - $limit }} restantes)
                                                </span>
                                                <span wire:loading wire:target="loadMoreDrill('{{ $mobileTaskStatus }}')">
                                                    Carregando...
                                                </span>
                                            </flux:button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="hidden gap-4 pb-4 md:flex">
                            @foreach ($drillTaskStatuses as $status)
                                @php
                                    $tasks = $this->getDrillColumnTasks($status);
                                    $total = $this->getDrillColumnTotal($status);
                                    $limit = $drillLimits[$status->value];
                                    $hasMore = $total > $limit;
                                @endphp

                                <div class="flex min-w-48 flex-1 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50">
                                    <div class="flex items-center justify-between border-b border-zinc-700 px-3 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <flux:icon :name="$status->icon()" class="size-4 text-{{ $status->color() }}-400" />
                                            <span class="text-sm font-medium text-zinc-300">{{ $status->label() }}</span>
                                        </div>
                                        <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                    </div>

                                    <div class="flex-1 overflow-y-auto p-2">
                                        @forelse ($tasks as $task)
                                            <div
                                                wire:key="drill-task-{{ $task->id }}"
                                                class="mb-2 rounded-lg border border-zinc-700 bg-zinc-800 p-2.5"
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
                                                </div>
                                            </div>
                                        @empty
                                            <div class="py-6 text-center text-xs text-zinc-600">Nenhuma task</div>
                                        @endforelse

                                        @if ($hasMore)
                                            <div class="mt-2">
                                                <flux:button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="w-full"
                                                    wire:click="loadMoreDrill('{{ $status->value }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="loadMoreDrill('{{ $status->value }}')"
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
                        <div class="flex items-center justify-between">
                            <flux:badge size="sm" color="zinc">{{ $this->features->count() }} features</flux:badge>
                            <flux:text class="text-xs text-zinc-500">Board em modo leitura</flux:text>
                        </div>

                        <div class="md:hidden">
                            <flux:select wire:model.live="mobileFeatureStatus" class="mb-4">
                                @foreach ($featureStatuses as $status)
                                    <flux:select.option value="{{ $status->value }}">
                                        {{ $status->label() }} ({{ $this->getColumnTotal($status) }})
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            @php
                                $mobileFeatureStatusEnum = FeatureStatus::from($mobileFeatureStatus);
                                $features = $this->getColumnFeatures($mobileFeatureStatusEnum);
                                $total = $this->getColumnTotal($mobileFeatureStatusEnum);
                                $limit = $featureLimits[$mobileFeatureStatus];
                                $hasMore = $total > $limit;
                            @endphp

                            <div class="flex flex-col gap-2">
                                @forelse ($features as $feature)
                                    @php
                                        $tasksCount = $feature->tasksCount();
                                        $completedCount = $feature->completedTasksCount();
                                    @endphp

                                    <div
                                        wire:key="mobile-feature-{{ $feature->id }}"
                                        class="rounded-lg border border-zinc-700 bg-zinc-800 p-3"
                                    >
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="text-sm font-medium text-zinc-200">{{ $feature->title }}</div>
                                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                                    <flux:badge size="sm" color="{{ $feature->status->color() }}" icon="{{ $feature->status->icon() }}">
                                                        {{ $feature->status->label() }}
                                                    </flux:badge>
                                                    @if ($feature->priority)
                                                        <flux:badge size="sm" color="{{ $feature->priority->color() }}" icon="{{ $feature->priority->icon() }}">
                                                            {{ $feature->priority->label() }}
                                                        </flux:badge>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>

                                        @if ($tasksCount > 0)
                                            <div class="mt-3">
                                                <div class="mb-1 flex items-center justify-between text-xs text-zinc-400">
                                                    <span>Progresso</span>
                                                    <span>{{ $completedCount }}/{{ $tasksCount }} tasks</span>
                                                </div>
                                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-700">
                                                    <div
                                                        class="h-full rounded-full bg-{{ $feature->status->color() }}-500"
                                                        style="width: {{ $feature->progress }}%"
                                                    ></div>
                                                </div>
                                            </div>
                                        @endif

                                        <div class="mt-3 flex justify-end">
                                            @if ($tasksCount > 0)
                                                <flux:button variant="ghost" size="sm" wire:click="enterDrill({{ $feature->id }})">
                                                    Ver tasks
                                                </flux:button>
                                            @else
                                                <flux:text class="text-xs text-zinc-500">Sem tasks</flux:text>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-lg border border-dashed border-zinc-700 py-8 text-center text-sm text-zinc-600">
                                        Nenhuma feature
                                    </div>
                                @endforelse
                            </div>

                            @if ($hasMore)
                                <div class="mt-3">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        class="w-full"
                                        wire:click="loadMoreFeatures('{{ $mobileFeatureStatus }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="loadMoreFeatures('{{ $mobileFeatureStatus }}')"
                                    >
                                        <span wire:loading.remove wire:target="loadMoreFeatures('{{ $mobileFeatureStatus }}')">
                                            Carregar mais ({{ $total - $limit }} restantes)
                                        </span>
                                        <span wire:loading wire:target="loadMoreFeatures('{{ $mobileFeatureStatus }}')">
                                            Carregando...
                                        </span>
                                    </flux:button>
                                </div>
                            @endif
                        </div>

                        <div class="-mx-4 hidden flex-1 gap-3 overflow-x-auto px-4 pb-4 md:flex">
                            @foreach ($featureStatuses as $status)
                                @php
                                    $features = $this->getColumnFeatures($status);
                                    $total = $this->getColumnTotal($status);
                                    $limit = $featureLimits[$status->value];
                                    $hasMore = $total > $limit;
                                @endphp

                                <div class="flex w-72 shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50">
                                    <div class="flex items-center justify-between border-b border-zinc-700 px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                                            <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                                        </div>
                                        <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                    </div>

                                    <div class="flex-1 space-y-3 overflow-y-auto p-3">
                                        @forelse ($features as $feature)
                                            @php
                                                $tasksCount = $feature->tasksCount();
                                                $completedCount = $feature->completedTasksCount();
                                                $specPreview = trim(Str::limit(strip_tags($feature->spec ?? ''), 120));
                                            @endphp

                                            <div wire:key="feature-card-{{ $feature->id }}" class="rounded-lg border border-zinc-700 bg-zinc-800 p-3">
                                                <div class="text-sm font-medium text-zinc-200">{{ $feature->title }}</div>

                                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                                    <flux:badge size="sm" color="{{ $feature->status->color() }}" icon="{{ $feature->status->icon() }}">
                                                        {{ $feature->status->label() }}
                                                    </flux:badge>

                                                    @if ($feature->priority)
                                                        <flux:badge size="sm" color="{{ $feature->priority->color() }}" icon="{{ $feature->priority->icon() }}">
                                                            {{ $feature->priority->label() }}
                                                        </flux:badge>
                                                    @endif

                                                    @if ($tasksCount > 0)
                                                        <flux:badge size="sm" color="zinc">
                                                            {{ $completedCount }}/{{ $tasksCount }} tasks
                                                        </flux:badge>
                                                    @endif
                                                </div>

                                                @if ($tasksCount > 0)
                                                    <div class="mt-3">
                                                        <div class="mb-1 flex items-center justify-between text-xs text-zinc-400">
                                                            <span>Progresso</span>
                                                            <span>{{ $feature->progress }}%</span>
                                                        </div>
                                                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-700">
                                                            <div
                                                                class="h-full rounded-full bg-{{ $feature->status->color() }}-500"
                                                                style="width: {{ $feature->progress }}%"
                                                            ></div>
                                                        </div>
                                                    </div>
                                                @endif

                                                @if ($specPreview !== '')
                                                    <flux:text class="mt-2 line-clamp-2 text-xs text-zinc-500">{{ $specPreview }}</flux:text>
                                                @endif

                                                <div class="mt-3 flex justify-end">
                                                    @if ($tasksCount > 0)
                                                        <flux:button variant="ghost" size="sm" wire:click="enterDrill({{ $feature->id }})">
                                                            Ver tasks
                                                        </flux:button>
                                                    @else
                                                        <flux:text class="text-xs text-zinc-500">Sem tasks</flux:text>
                                                    @endif
                                                </div>
                                            </div>
                                        @empty
                                            <div class="py-8 text-center text-sm text-zinc-600">Nenhuma feature</div>
                                        @endforelse

                                        @if ($hasMore)
                                            <div class="mt-2">
                                                <flux:button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="w-full"
                                                    wire:click="loadMoreFeatures('{{ $status->value }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="loadMoreFeatures('{{ $status->value }}')"
                                                >
                                                    <span wire:loading.remove wire:target="loadMoreFeatures('{{ $status->value }}')">
                                                        Carregar mais ({{ $total - $limit }} restantes)
                                                    </span>
                                                    <span wire:loading wire:target="loadMoreFeatures('{{ $status->value }}')">
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

            <flux:tab.panel name="features">
                <div class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <flux:badge size="sm" color="zinc">{{ $this->features->count() }} features</flux:badge>
                        <flux:text class="text-xs text-zinc-500">Specs em modo leitura</flux:text>
                    </div>

                    @if ($this->features->isEmpty())
                        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-16">
                            <flux:icon name="rectangle-stack" class="mb-3 size-12 text-zinc-600" />
                            <flux:heading size="sm" class="text-zinc-400">Nenhuma feature neste projeto</flux:heading>
                            <flux:text class="mt-1 text-sm text-zinc-500">Ainda não há specs para visualizar.</flux:text>
                        </div>
                    @else
                        <div class="hidden lg:flex lg:gap-4" style="min-height: 500px;">
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
                                        <flux:text class="mt-1 text-sm text-zinc-500">Clique em uma feature na lista para visualizar a spec.</flux:text>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden">
                            @foreach ($this->features as $feature)
                                @php
                                    $tasksCount = $feature->tasksCount();
                                    $completedCount = $feature->completedTasksCount();
                                    $isOverdue = $feature->due_date?->isPast() ?? false;
                                    $specPreview = trim(Str::limit(strip_tags($feature->spec ?? ''), 120));
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

            <flux:tab.panel name="docs">
                <div class="flex flex-col gap-4">
                    @if ($this->projectDocuments->isEmpty())
                        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-12">
                            <flux:icon name="document-text" class="mb-3 size-10 text-zinc-600" />
                            <flux:text class="text-sm text-zinc-500">Nenhum documento neste projeto.</flux:text>
                        </div>
                    @else
                        <div class="hidden lg:flex lg:gap-4" style="min-height: 500px;">
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

                            <div class="w-2/3 overflow-y-auto">
                                @if ($this->selectedDocument)
                                    <div class="flex flex-col gap-4">
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

                                        <div class="rounded-lg border border-zinc-700 bg-zinc-900/30 p-4">
                                            <livewire:markdown-viewer
                                                :content="$this->selectedDocument->content"
                                                :show-copy-buttons="false"
                                                :key="'preview-'.$this->selectedDocument->id"
                                            />
                                        </div>
                                    </div>
                                @else
                                    <div class="flex h-full flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-16">
                                        <flux:icon name="document-magnifying-glass" class="mb-3 size-12 text-zinc-600" />
                                        <flux:heading size="sm" class="text-zinc-400">Selecione um documento</flux:heading>
                                        <flux:text class="mt-1 text-sm text-zinc-500">Clique em um documento na lista para visualizar o preview.</flux:text>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden">
                            @foreach ($this->projectDocuments as $document)
                                <div
                                    wire:key="doc-mobile-{{ $document->id }}"
                                    wire:click="selectDocument({{ $document->id }})"
                                    x-on:click="$flux.modal('doc-preview-modal').show()"
                                    class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-700 bg-zinc-900/50 p-3 transition hover:border-zinc-500"
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
                                </div>
                            @endforeach
                        </div>

                        <flux:modal name="doc-preview-modal" variant="flyout" class="w-full max-w-2xl">
                            @if ($this->selectedDocument)
                                <div class="flex flex-col gap-4">
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

                                    <div class="overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-900/30 p-4">
                                        <livewire:markdown-viewer
                                            :content="$this->selectedDocument->content"
                                            :show-copy-buttons="false"
                                            :key="'preview-mobile-'.$this->selectedDocument->id"
                                        />
                                    </div>
                                </div>
                            @endif
                        </flux:modal>
                    @endif
                </div>
            </flux:tab.panel>

            <flux:tab.panel name="metrics">
                @php
                    $metrics = $this->metrics;
                @endphp

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <flux:card class="space-y-2">
                        <div class="flex items-center gap-2">
                            <flux:icon name="clipboard-document-list" class="size-5 text-zinc-400" />
                            <flux:text class="text-sm text-zinc-400">Total de Tasks</flux:text>
                        </div>
                        <flux:heading size="xl">{{ $metrics['total'] }}</flux:heading>
                    </flux:card>

                    <flux:card class="space-y-2">
                        <div class="flex items-center gap-2">
                            <flux:icon name="clock" class="size-5 text-zinc-400" />
                            <flux:text class="text-sm text-zinc-400">Horas Trabalhadas</flux:text>
                        </div>
                        <flux:heading size="xl">{{ $metrics['total_hours'] }}h</flux:heading>
                    </flux:card>

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

                    <flux:card class="space-y-2">
                        <div class="flex items-center gap-2">
                            <flux:icon name="trophy" class="size-5 text-amber-400" />
                            <flux:text class="text-sm text-zinc-400">Concluídas</flux:text>
                        </div>
                        <flux:heading size="xl">{{ $metrics['by_status']['done'] ?? 0 }}</flux:heading>
                    </flux:card>

                    <flux:card class="space-y-2">
                        <div class="flex items-center gap-2">
                            <flux:icon name="code-bracket" class="size-5 text-violet-400" />
                            <flux:text class="text-sm text-zinc-400">Commits</flux:text>
                        </div>
                        <flux:heading size="xl">{{ $metrics['total_commits'] }}</flux:heading>
                    </flux:card>

                    <flux:card class="space-y-2">
                        <div class="flex items-center gap-2">
                            <flux:icon name="document-text" class="size-5 text-sky-400" />
                            <flux:text class="text-sm text-zinc-400">Arquivos Alterados</flux:text>
                        </div>
                        <flux:heading size="xl">{{ $metrics['total_files_changed'] }}</flux:heading>
                    </flux:card>

                    <flux:card class="space-y-2">
                        <div class="flex items-center gap-2">
                            <flux:icon name="squares-2x2" class="size-5 text-indigo-400" />
                            <flux:text class="text-sm text-zinc-400">Features</flux:text>
                        </div>
                        <flux:heading size="xl">{{ $metrics['feature_count'] }}</flux:heading>
                    </flux:card>

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

                <div class="mt-6">
                    <flux:heading size="sm" class="mb-3">Features por Status</flux:heading>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        @foreach (FeatureStatus::cases() as $featureStatus)
                            @php
                                $featureCount = $metrics['features_by_status'][$featureStatus->value] ?? 0;
                            @endphp

                            <div class="flex items-center gap-3 rounded-lg border border-zinc-700 bg-zinc-900/50 px-4 py-3">
                                <flux:icon :name="$featureStatus->icon()" class="size-5 text-{{ $featureStatus->color() }}-400" />
                                <div class="flex flex-1 items-center justify-between">
                                    <span class="text-sm text-zinc-300">{{ $featureStatus->label() }}</span>
                                    <flux:badge size="sm" color="{{ $featureStatus->color() }}">{{ $featureCount }}</flux:badge>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

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

        @if ($showIssuesPanel)
            <aside class="w-full xl:sticky xl:top-0 xl:h-screen xl:w-96 xl:self-start">
                <flux:card class="flex h-full flex-col gap-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <flux:heading size="sm">Issues de Feedback</flux:heading>
                            <flux:text class="text-xs text-zinc-500">
                                Comentários laterais que podem virar feature.
                            </flux:text>
                        </div>

                        <flux:badge size="sm" color="zinc">{{ $this->stakeholderIssues->count() }}</flux:badge>
                    </div>

                    <div class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
                        @forelse ($this->stakeholderIssues as $issue)
                            <div wire:key="stakeholder-issue-{{ $issue->id }}" class="rounded-lg border border-zinc-700 bg-zinc-900/60 p-3">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <flux:badge size="sm" color="{{ $issue->status->color() }}" icon="{{ $issue->status->icon() }}">
                                        {{ $issue->status->label() }}
                                    </flux:badge>
                                    <span class="text-xs text-zinc-500">{{ $issue->created_at->diffForHumans() }}</span>
                                </div>

                                <flux:text class="text-sm text-zinc-200">{{ $issue->comment }}</flux:text>

                                <div class="mt-2 flex items-center gap-1.5 text-xs text-zinc-500">
                                    <flux:icon name="envelope" class="size-3.5" />
                                    <span>{{ $issue->stakeholder?->email ?? $this->stakeholder->email }}</span>
                                </div>

                                @if ($issue->feature)
                                    <div class="mt-2 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2 py-1.5 text-xs text-emerald-200">
                                        Feature vinculada: #F-{{ $issue->feature->id }} {{ $issue->feature->title }}
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-zinc-700 py-8 text-center text-sm text-zinc-500">
                                Nenhuma issue enviada ainda.
                            </div>
                        @endforelse
                    </div>

                    <form wire:submit="addIssue" class="space-y-3 rounded-lg border border-zinc-700 bg-zinc-900/50 p-3">
                        <flux:textarea
                            wire:model="newIssueComment"
                            label="Novo comentário / issue"
                            rows="4"
                            resize="vertical"
                            placeholder="Descreva o problema, ideia ou melhoria..."
                        />
                        <flux:error name="newIssueComment" />

                        <flux:button type="submit" variant="primary" class="w-full" icon="plus">
                            Enviar
                        </flux:button>
                    </form>
                </flux:card>
            </aside>
        @endif
    </div>

    <div class="border-t border-zinc-800 px-6 py-4">
        <div class="flex w-full items-center justify-between text-sm text-zinc-500">
            <span>SoloBoard</span>
            <span>Link de acompanhamento exclusivo</span>
        </div>
    </div>
</div>
