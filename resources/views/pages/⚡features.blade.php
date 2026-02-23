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

        return $query->get();
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
    public function refreshBoard(): void
    {
        unset($this->features);
        unset($this->projects);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col p-4 sm:p-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">Features</flux:heading>
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
                        @php
                            $isExpanded = $this->isExpanded($feature->id);
                            $tasksCount = $feature->tasksCount();
                            $completedCount = $feature->completedTasksCount();
                            $totalTime = $feature->total_time;
                        @endphp

                        <div
                            wire:key="feature-{{ $feature->id }}"
                            class="group rounded-lg border border-zinc-700 bg-zinc-800 transition-all duration-200 hover:border-zinc-500 hover:shadow-lg hover:shadow-zinc-900/50"
                        >
                            {{-- Card Header --}}
                            <div
                                class="cursor-pointer p-3"
                                wire:click="$dispatch('open-feature-modal', { featureId: {{ $feature->id }} })"
                            >
                                {{-- Title --}}
                                <h3 class="line-clamp-2 text-sm font-medium text-zinc-200">
                                    {{ $feature->title }}
                                </h3>

                                {{-- Project Info --}}
                                @if ($feature->project)
                                    <div class="mt-2 flex items-center gap-1.5 border-l-2 pl-2" style="border-color: {{ $feature->project->color }}">
                                        <span class="text-xs">{{ $feature->project->emoji }}</span>
                                        <span class="truncate text-xs text-zinc-400">{{ $feature->project->name }}</span>
                                    </div>
                                @endif

                                {{-- Progress Bar --}}
                                <div class="mt-3">
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="text-zinc-400">Progresso</span>
                                        <span class="font-medium text-zinc-300">{{ $completedCount }}/{{ $tasksCount }} tasks</span>
                                    </div>
                                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-700">
                                        <div
                                            class="h-full rounded-full bg-{{ $status->color() }}-500 transition-all duration-300"
                                            style="width: {{ $feature->progress }}%"
                                        ></div>
                                    </div>
                                </div>

                                {{-- Badges Row --}}
                                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                    {{-- Priority Badge --}}
                                    @if ($feature->priority)
                                        <flux:badge size="sm" color="{{ $feature->priority->color() }}" icon="{{ $feature->priority->icon() }}">
                                            {{ $feature->priority->label() }}
                                        </flux:badge>
                                    @endif

                                    {{-- Time Badge --}}
                                    @if ($totalTime > 0)
                                        <flux:badge size="sm" color="zinc" icon="clock">
                                            {{ $this->formatDuration($totalTime) }}
                                        </flux:badge>
                                    @endif

                                    {{-- Running Timer --}}
                                    @if ($feature->isRunning())
                                        <flux:badge size="sm" color="emerald" class="animate-pulse">
                                            <div class="mr-1 size-2 rounded-full bg-emerald-400"></div>
                                            Timer
                                        </flux:badge>
                                    @endif

                                    {{-- Due Date --}}
                                    @if ($feature->due_date)
                                        @php
                                            $isOverdue = $feature->due_date->isPast();
                                        @endphp
                                        <flux:badge size="sm" color="{{ $isOverdue ? 'red' : 'zinc' }}" icon="{{ $isOverdue ? 'exclamation-triangle' : 'calendar' }}">
                                            {{ $feature->due_date->format('d/m') }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </div>

                            {{-- Expand/Collapse Tasks --}}
                            @if ($tasksCount > 0)
                                <div class="border-t border-zinc-700">
                                    <button
                                        type="button"
                                        wire:click.stop="toggleExpanded({{ $feature->id }})"
                                        class="flex w-full items-center justify-between px-3 py-2 text-xs text-zinc-400 transition hover:bg-zinc-700/50 hover:text-zinc-300"
                                    >
                                        <span>{{ $tasksCount }} {{ $tasksCount === 1 ? 'task' : 'tasks' }}</span>
                                        <flux:icon
                                            :name="$isExpanded ? 'chevron-up' : 'chevron-down'"
                                            class="size-4"
                                        />
                                    </button>

                                    {{-- Tasks List (Expandable) --}}
                                    @if ($isExpanded)
                                        <div class="border-t border-zinc-700/50 bg-zinc-800/50 px-3 py-2">
                                            <ul class="space-y-1.5">
                                                @foreach ($feature->tasks->sortBy('sort_order') as $task)
                                                    <li
                                                        wire:click.stop="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                                        class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-xs transition hover:bg-zinc-700"
                                                    >
                                                        {{-- Status indicator --}}
                                                        <div class="size-2 shrink-0 rounded-full bg-{{ $task->status->color() }}-400"></div>

                                                        {{-- Title --}}
                                                        <span class="flex-1 truncate text-zinc-300">{{ $task->title }}</span>

                                                        {{-- Priority --}}
                                                        @if ($task->priority)
                                                            <flux:icon
                                                                :name="$task->priority->icon()"
                                                                class="size-3 shrink-0 text-{{ $task->priority->color() }}-400"
                                                            />
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
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

</div>
