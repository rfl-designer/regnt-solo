<?php

use App\Enums\FeaturePriority;
use App\Enums\FeatureStatus;
use App\Models\Feature;
use App\Models\Project;
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

    /** @var array<string, int> */
    public array $limits = [
        'draft' => 20,
        'backlog' => 20,
        'todo' => 20,
        'doing' => 20,
        'done' => 20,
    ];

    /** @var array<string, bool> */
    public array $expanded = [];

    /** @var list<FeatureStatus> */
    public array $kanbanStatuses = [];

    public function mount(): void
    {
        $this->kanbanStatuses = [
            FeatureStatus::Backlog,
            FeatureStatus::Todo,
            FeatureStatus::Doing,
            FeatureStatus::Done,
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
    {{-- Kanban Board --}}
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
                    @forelse ($features as $feature)
                        <x-feature-card
                            :feature="$feature"
                            :expanded="$this->isExpanded($feature->id)"
                            :show-project="true"
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
