<?php

use App\Enums\ActivityStatus;
use App\Exceptions\DomainRefusal;
use App\Exceptions\SingleActiveEmergencyException;
use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Services\FlowMetricsService;
use App\Services\PullQueueService;
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
    public string $filterServiceClass = '';

    #[Url(as: 'due')]
    public string $filterDueDate = '';

    #[Url(as: 'overdue')]
    public bool $filterOverdue = false;

    /** @var array<string, int> */
    public array $limits = [
        'backlog' => 20,
        'awaiting_approval' => 20,
        'todo' => 20,
        'doing' => 20,
        'waiting' => 20,
        'awaiting_validation' => 20,
        'done' => 20,
    ];

    /** @var list<ActivityStatus> */
    public array $kanbanStatuses = [];

    public function mount(): void
    {
        $this->kanbanStatuses = ActivityStatus::boardOrder();
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    public function getColumnTasks(ActivityStatus $status, bool $withProject = true): \Illuminate\Database\Eloquent\Collection
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
     * The Pronto column, in pull order (issue #144).
     *
     * The page doesn't rank anything itself: it asks
     * {@see PullQueueService} for the same queue the `get-pull-queue` MCP
     * tool reads, scoped by whatever filters the toolbar has on. That is
     * why the column no longer sorts by `sort_order` — the order is
     * derived, so there is nothing for a drag inside the column to change.
     *
     * @return \Illuminate\Support\Collection<int, \App\Services\PullQueueEntry>
     */
    #[Computed]
    public function pullQueue(): \Illuminate\Support\Collection
    {
        return app(PullQueueService::class)->queue(fn ($query) => $this->applyFilters($query));
    }

    /**
     * Get total count for a column (for "load more" button).
     */
    public function getColumnTotal(ActivityStatus $status): int
    {
        return $this->buildColumnQuery($status)->count();
    }

    /**
     * Get total estimated minutes for a column.
     */
    public function getColumnEstimate(ActivityStatus $status): int
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
    public function hasUnassignedTasks(ActivityStatus $status): bool
    {
        return $this->buildColumnQuery($status)->whereNull('project_id')->exists();
    }

    public function loadMore(string $status): void
    {
        $this->limits[$status] += 20;
    }

    public function handleSort(int|string $id, int $position, string $groupId): void
    {
        $task = Activity::findOrFail((int) $id);
        $newStatus = ActivityStatus::from($groupId);

        // The internal wait (Esperando) has no client to auto-fill "esperando
        // quem" from, so it always needs an interactive answer. Defer the
        // move to the blocking mini modal instead of writing straight away;
        // the modal performs the actual update once a name is given.
        if ($newStatus->isInternalWaiting() && $task->status !== ActivityStatus::Waiting) {
            $this->dispatch('open-waiting-for-modal', taskId: $task->id, status: $newStatus->value);

            return;
        }

        try {
            DB::transaction(function () use ($task, $newStatus, $position): void {
                if ($newStatus === ActivityStatus::Done && $task->status !== ActivityStatus::Done) {
                    // Add to daily plan if not already there (Observer syncs completed_at)
                    $dailyPlan = DailyPlan::getOrCreateForDate(Carbon::today());

                    if (! $dailyPlan->tasks()->where('activity_id', $task->id)->exists()) {
                        $maxOrder = $dailyPlan->tasks()->max('daily_plan_activity.sort_order') ?? -1;
                        $dailyPlan->tasks()->attach($task->id, ['sort_order' => $maxOrder + 1]);
                    }

                    $task->markAsDone();

                    Flux::toast(variant: 'success', heading: 'Task concluída', text: $task->title);
                } else {
                    $task->update(['status' => $newStatus]);
                }

                // Pronto has no manual order to write: the column renders
                // whatever the pull queue ranks (issue #144), so a drop
                // inside it only ever means "this is where you let go",
                // never "this is the new position". Moves into and out of
                // the column are untouched — only the reordering is gone.
                if ($newStatus !== ActivityStatus::Todo) {
                    $this->reorderColumn($newStatus, $task->id, $position);
                }
            });
        } catch (SingleActiveEmergencyException) {
            // Dragging a concluded Emergência back onto the board would
            // light a second one. That is a choice, not a dead end: hand the
            // pending move to the shared modal, which asks "Manter a atual /
            // Substituir" and applies the move itself if the user swaps.
            $this->dispatch('open-emergency-modal', taskId: $task->id, status: $newStatus->value);
        } catch (DomainRefusal $e) {
            // No client-side pre-block: the drop is optimistic, the domain
            // guard is the authority, and the re-render that follows this
            // request puts the card back where it came from. The toast is
            // what explains why it bounced.
            Flux::toast(variant: 'danger', heading: 'Não foi possível mover', text: $e->getMessage());
        }
    }

    /**
     * The flow metrics behind the SLE chip and the cards' aging
     * (issue #145). Held as a computed so a single instance serves the
     * whole render: it caches the status-history clocks it reads, which is
     * what keeps "one query per card" from happening.
     */
    #[Computed]
    public function flow(): FlowMetricsService
    {
        return app(FlowMetricsService::class);
    }

    /**
     * The WIP limit for Fazendo. Read straight from config on every render
     * so the "n/2" indicator can never drift from the number the guard in
     * ActivityObserver enforces.
     */
    public function wipLimit(): int
    {
        return (int) config('soloboard.wip_limit_doing', 2);
    }

    /**
     * How many board items are in Fazendo right now — deliberately ignoring
     * the column filters, because the limit is about the whole board and a
     * filtered "1/2" would be a lie.
     */
    public function doingWipCount(): int
    {
        return Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Doing)
            ->count();
    }

    /**
     * The colour of the "n/2" badge: red from the moment the column is
     * full, and it stays red at 3/2 when an Emergência has furado o limite.
     * Exposed as a method so the decision itself is testable, rather than
     * only inferrable from rendered HTML.
     */
    public function wipBadgeColor(): string
    {
        return $this->doingWipCount() >= $this->wipLimit()
            ? 'red'
            : ActivityStatus::Doing->color();
    }

    #[On('task-updated')]
    #[On('task-created')]
    public function refreshBoard(): void
    {
        unset($this->projects, $this->pullQueue, $this->flow);
    }

    /**
     * Build the base query for a column with filters applied.
     */
    private function buildColumnQuery(ActivityStatus $status): \Illuminate\Database\Eloquent\Builder
    {
        $query = Activity::query()
            ->leaf()
            ->with('project.client', 'client', 'parent', 'timeEntries')
            ->withCount('commits')
            ->where('status', $status);

        if ($status === ActivityStatus::Done) {
            $query->whereBetween('completed_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ]);
        }

        $this->applyFilters($query);

        return $query;
    }

    /**
     * Apply the toolbar filters to a column query. Shared with the pull
     * queue, so the Pronto column is filtered exactly like every other
     * column even though its ordering comes from elsewhere.
     */
    private function applyFilters(\Illuminate\Database\Eloquent\Builder $query): void
    {
        if ($this->filterProject !== '') {
            $query->where('project_id', (int) $this->filterProject);
        }

        if ($this->filterServiceClass !== '') {
            $query->where('service_class', $this->filterServiceClass);
        }

        if ($this->filterDueDate !== '') {
            $query->whereNotNull('due_date');
            match ($this->filterDueDate) {
                'today' => $query->whereDate('due_date', today()),
                'week' => $query->whereBetween('due_date', [today(), today()->endOfWeek()]),
                '7days' => $query->whereBetween('due_date', [today(), today()->addDays(7)]),
                '30days' => $query->whereBetween('due_date', [today(), today()->addDays(30)]),
                default => null,
            };
        }

        if ($this->filterOverdue) {
            $query->whereNotNull('due_date')
                ->where('due_date', '<', Carbon::today());
        }
    }

    /**
     * Insert the moved task at the given position within its column and
     * renumber every task in that column to a unique, sequential sort_order.
     */
    private function reorderColumn(ActivityStatus $status, int $movedId, int $position): void
    {
        $query = Activity::query()
            ->leaf()
            ->where('status', $status)
            ->where('id', '!=', $movedId);

        if ($status === ActivityStatus::Done) {
            $query->whereBetween('completed_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ]);
        }

        $ids = $query->orderBy('sort_order')->orderBy('id')->pluck('id')->all();

        $position = max(0, min($position, count($ids)));
        array_splice($ids, $position, 0, [$movedId]);

        foreach ($ids as $index => $id) {
            Activity::query()->where('id', $id)->update(['sort_order' => $index]);
        }
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col p-4 sm:p-6">
    @php
        // Every card that can show aging, read in one go before the first
        // column renders (issue #145). Per-column warming would cost a
        // query per collection — including for the columns that never show
        // aging at all.
        $this->flow->warmBoard();
    @endphp

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">Kanban</flux:heading>

            {{-- The SLE chip (issue #145): the board's promise, or the
                 honest admission that it doesn't have one yet. Clicking it
                 goes to Fluxo, where the number is explained rather than
                 just asserted. --}}
            <flux:tooltip content="Ver a página Fluxo: baseline, distribuição e o que está envelhecendo.">
                <a href="{{ route('flow') }}" wire:navigate data-test="sle-chip">
                    <flux:badge
                        size="sm"
                        :color="$this->flow->isUsable() ? 'emerald' : 'zinc'"
                        icon="arrow-trending-up"
                        class="cursor-pointer"
                    >
                        {{ $this->flow->label() }}
                    </flux:badge>
                </a>
            </flux:tooltip>
        </div>

        <div class="flex items-center gap-3">
            {{-- Project filter --}}
            <flux:select wire:model.live="filterProject" size="sm" class="w-44">
                <option value="">Todos projetos</option>
                @foreach ($this->projects as $project)
                    <option value="{{ $project->id }}">{{ $project->emoji }} {{ $project->name }}</option>
                @endforeach
            </flux:select>

            {{-- Service class filter --}}
            <flux:select wire:model.live="filterServiceClass" size="sm" class="w-36">
                <option value="">Classes de serviço</option>
                @foreach (App\Enums\ServiceClass::cases() as $serviceClass)
                    <option value="{{ $serviceClass->value }}">{{ $serviceClass->label() }}</option>
                @endforeach
            </flux:select>

            {{-- Due date filter --}}
            <flux:select wire:model.live="filterDueDate" size="sm" class="w-40">
                <option value="">Vence em</option>
                <option value="today">Hoje</option>
                <option value="week">Esta semana</option>
                <option value="7days">Próximos 7 dias</option>
                <option value="30days">Próximos 30 dias</option>
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

            {{-- Color legend --}}
            <x-color-legend />
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
        class="-mx-4 flex flex-1 gap-3 overflow-x-auto px-4 pb-4 sm:mx-0 sm:gap-4 sm:px-0"
    >
        @foreach ($kanbanStatuses as $status)
            @php
                $isDone = $status === \App\Enums\ActivityStatus::Done;
                $isDoing = $status === \App\Enums\ActivityStatus::Doing;
                $isTodo = $status === \App\Enums\ActivityStatus::Todo;
                $limit = $limits[$status->value];

                // Pronto is rendered from the pull queue, so the two
                // column queries (and the models they hydrate) would be
                // loaded only to be thrown away. Its total comes from the
                // queue itself, which is the same universe.
                $tasks = $isTodo ? collect() : $this->getColumnTasks($status, withProject: true);
                $unassignedTasks = $isTodo ? collect() : $this->getColumnTasks($status, withProject: false);
                $queueEntries = $isTodo ? $this->pullQueue->take($limit) : collect();
                $total = $isTodo ? $this->pullQueue->count() : $this->getColumnTotal($status);

                $estimate = $this->getColumnEstimate($status);
                $estimateFormatted = $this->formatDuration($estimate);
                $hasMore = $total > $limit;
                $wipLimit = $this->wipLimit();
                $wipCount = $isDoing ? $this->doingWipCount() : 0;
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
                                    @if ($estimateFormatted)
                                        <flux:badge size="sm" color="zinc" icon="clock">{{ $estimateFormatted }}</flux:badge>
                                    @endif
                                    <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                                    <button
                                        type="button"
                                        @click.stop="$wire.$dispatch('open-quick-add-with-status', { status: '{{ $status->value }}' })"
                                        class="rounded p-1 text-zinc-500 transition hover:bg-zinc-700 hover:text-zinc-300"
                                        title="Nova task em {{ $status->label() }}"
                                    >
                                        <flux:icon name="plus" class="size-4" />
                                    </button>
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
                            @if ($estimateFormatted)
                                <flux:badge size="sm" color="zinc" icon="clock">{{ $estimateFormatted }}</flux:badge>
                            @endif
                            @if ($isDoing)
                                {{-- "n/2": red once the column is full, and it
                                     legitimately reads "3/2" when an Emergência
                                     has furado o limite. --}}
                                <flux:tooltip content="Limite de {{ $wipLimit }} itens em Fazendo — só uma Emergência fura o limite.">
                                    <flux:badge size="sm" color="{{ $this->wipBadgeColor() }}">
                                        {{ $wipCount }}/{{ $wipLimit }}
                                    </flux:badge>
                                </flux:tooltip>
                            @else
                                <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                            @endif
                            <flux:button
                                wire:click="$dispatch('open-quick-add-with-status', { status: '{{ $status->value }}' })"
                                variant="ghost"
                                size="sm"
                                icon="plus"
                                class="ml-1 !p-1"
                                title="Nova task em {{ $status->label() }}"
                            />
                        </div>
                    </div>
                @endif

                {{-- Tasks List --}}
                <div
                    @if ($isDone) x-show="!doneCollapsed" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @endif
                    class="flex-1 overflow-y-auto p-2"
                >
                    @if ($isTodo)
                        {{-- Pronto: the pull queue, not a hand-sorted list
                             (issue #144). Cards can still be dragged out (and
                             dropped in), but there is no order to rearrange
                             here — the degraus below are derived. --}}
                        <div class="mb-2 flex items-center gap-1.5 px-1 text-xs text-zinc-500">
                            <flux:icon name="bars-arrow-down" class="size-3.5" />
                            <span>Ordem automática da fila</span>
                        </div>

                        {{-- `sort: false` is what actually removes the
                             internal reordering: without it the card is
                             draggable anywhere inside the list (no handle
                             means Sortable treats the whole item as one),
                             so the user could rearrange Pronto, fire a
                             pointless handleSort and watch the order snap
                             back. Sortable still lets the card be pulled
                             out to another column and still accepts drops
                             from them — only sorting within this list is
                             off. See `getConfigurationOverrides()` in
                             livewire/dist/livewire.esm.js. --}}
                        <ul
                            wire:sort="handleSort"
                            wire:sort:group="tasks"
                            wire:sort:group-id="{{ $status->value }}"
                            wire:sort:config="{ sort: false }"
                            class="kanban-dropzone flex min-h-[2rem] flex-col gap-2 rounded-lg transition-colors duration-200"
                        >
                            @php $previousReason = null; @endphp

                            @forelse ($queueEntries as $entry)
                                @if ($entry->reason !== $previousReason)
                                    @php
                                        // Written out in full: Tailwind scans these
                                        // templates as plain text, so an interpolated
                                        // `text-{{ $color }}-400/80` would never be
                                        // generated and the heading would render
                                        // colourless.
                                        $degrauClass = match ($entry->reason) {
                                            \App\Enums\PullQueueReason::Emergency => 'text-red-400/80',
                                            \App\Enums\PullQueueReason::FixedDateAtRisk => 'text-amber-400/80',
                                            \App\Enums\PullQueueReason::Fifo => 'text-zinc-400/80',
                                        };
                                    @endphp

                                    <li
                                        wire:key="pull-queue-degrau-{{ $entry->reason->value }}"
                                        wire:sort:ignore
                                        class="flex items-center gap-2 pt-1 first:pt-0"
                                    >
                                        <span class="text-[0.65rem] font-medium tracking-wide {{ $degrauClass }} uppercase">
                                            {{ $entry->reason->label() }}
                                        </span>
                                        <span class="h-px flex-1 bg-zinc-700/60"></span>
                                    </li>
                                    @php $previousReason = $entry->reason; @endphp
                                @endif

                                <x-pull-queue-card
                                    :entry="$entry"
                                    :aging-border="$this->flow->agingBorderClass($entry->activity)"
                                    :aging-tooltip="$this->flow->agingTooltip($entry->activity)"
                                />
                            @empty
                                <li class="py-8 text-center text-sm text-zinc-600">
                                    Nenhuma task
                                </li>
                            @endforelse
                        </ul>
                    @else
                    <ul
                        wire:sort="handleSort"
                        wire:sort:group="tasks"
                        wire:sort:group-id="{{ $status->value }}"
                        class="kanban-dropzone flex min-h-[2rem] flex-col gap-2 rounded-lg transition-colors duration-200"
                    >
                        @forelse ($tasks as $task)
                            @php
                                // Aging (issue #145): the card wears the SLE
                                // it is burning through. Null on both counts
                                // when there is no usable baseline — no
                                // promise, no alarm.
                                $agingBorder = $this->flow->agingBorderClass($task);
                                $agingTooltip = $this->flow->agingTooltip($task);
                            @endphp

                            <li wire:key="task-{{ $task->id }}" wire:sort:item="{{ $task->id }}" class="kanban-card">
                                <x-maybe-tooltip :content="$agingTooltip">
                                <div
                                    class="group cursor-pointer rounded-lg border {{ $agingBorder ?? 'border-zinc-700' }} bg-zinc-800 p-3 transition-all duration-200 hover:border-zinc-500 hover:shadow-lg hover:shadow-zinc-900/50"
                                    wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                >
                                    {{-- Card Top: Handle + Title --}}
                                    <div class="flex items-start gap-2">
                                        <div wire:sort:handle class="mt-0.5 shrink-0 cursor-grab text-zinc-600 transition-colors hover:text-zinc-300 active:cursor-grabbing">
                                            <flux:icon name="grip-vertical" class="size-4" />
                                        </div>
                                        <span class="line-clamp-2 flex-1 text-sm font-medium text-zinc-200">{{ $task->title }}</span>
                                        @if ($task->effective_client)
                                            <flux:tooltip :content="$task->effective_client->name">
                                                <div class="mt-1 size-2.5 shrink-0 rounded-full" style="background-color: {{ $task->effective_client->color }}"></div>
                                            </flux:tooltip>
                                        @endif
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
                                        {{-- Derived Label Badge --}}
                                        <flux:badge size="sm" color="{{ $task->type->color() }}" icon="{{ $task->type->icon() }}">
                                            {{ $task->derivedLabel() }}
                                        </flux:badge>

                                        {{-- Service Class Badge — an Emergência
                                             carries its motivo in the tooltip, so
                                             the justification travels with the card. --}}
                                        @if ($task->service_class === \App\Enums\ServiceClass::Emergency)
                                            <flux:tooltip :content="$task->emergency_reason ?? 'Emergência'">
                                                <flux:badge size="sm" color="red" icon="fire">
                                                    {{ $task->service_class->label() }}
                                                </flux:badge>
                                            </flux:tooltip>
                                        @elseif ($task->service_class)
                                            <flux:badge size="sm" color="{{ $task->service_class->color() }}" icon="{{ $task->service_class->icon() }}">
                                                {{ $task->service_class->label() }}
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

                                        {{-- Waiting Badge --}}
                                        @if ($task->isWaiting())
                                            <flux:badge size="sm" color="{{ $task->status->color() }}" icon="{{ $task->status->icon() }}">
                                                ⏳ {{ $task->waiting_for }} · há {{ $task->waitingDays() }} {{ $task->waitingDays() === 1 ? 'dia' : 'dias' }}
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
                                </x-maybe-tooltip>
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
                                class="kanban-dropzone flex flex-col gap-2 rounded-lg transition-colors duration-200"
                            >
                                @foreach ($unassignedTasks as $task)
                                    @php
                                        $agingBorder = $this->flow->agingBorderClass($task);
                                        $agingTooltip = $this->flow->agingTooltip($task);
                                    @endphp

                                    <li wire:key="task-{{ $task->id }}" wire:sort:item="{{ $task->id }}" class="kanban-card">
                                        <x-maybe-tooltip :content="$agingTooltip">
                                        <div
                                            class="group cursor-pointer rounded-lg border {{ $agingBorder ?? 'border-zinc-700/50' }} bg-zinc-800/50 p-3 transition-all duration-200 hover:border-zinc-500 hover:shadow-lg hover:shadow-zinc-900/50"
                                            wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                        >
                                            {{-- Card Top: Handle + Title --}}
                                            <div class="flex items-start gap-2">
                                                <div wire:sort:handle class="mt-0.5 shrink-0 cursor-grab text-zinc-600 transition-colors hover:text-zinc-300 active:cursor-grabbing">
                                                    <flux:icon name="grip-vertical" class="size-4" />
                                                </div>
                                                <span class="line-clamp-2 flex-1 text-sm font-medium text-zinc-300">{{ $task->title }}</span>
                                                @if ($task->effective_client)
                                                    <flux:tooltip :content="$task->effective_client->name">
                                                        <div class="mt-1 size-2.5 shrink-0 rounded-full" style="background-color: {{ $task->effective_client->color }}"></div>
                                                    </flux:tooltip>
                                                @endif
                                            </div>

                                            {{-- Badges Row --}}
                                            <div class="mt-2 flex flex-wrap items-center gap-1.5" wire:sort:ignore>
                                                @if ($task->service_class === \App\Enums\ServiceClass::Emergency)
                                                    <flux:tooltip :content="$task->emergency_reason ?? 'Emergência'">
                                                        <flux:badge size="sm" color="red" icon="fire">
                                                            {{ $task->service_class->label() }}
                                                        </flux:badge>
                                                    </flux:tooltip>
                                                @elseif ($task->service_class)
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

                                                @if ($task->isWaiting())
                                                    <flux:badge size="sm" color="{{ $task->status->color() }}" icon="{{ $task->status->icon() }}">
                                                        ⏳ {{ $task->waiting_for }} · há {{ $task->waitingDays() }} {{ $task->waitingDays() === 1 ? 'dia' : 'dias' }}
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
                                        </x-maybe-tooltip>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
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
