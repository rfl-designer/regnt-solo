<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\DailyPlan;
use App\Services\AiAssistantService;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $date = '';

    public string $filterProjectId = '';

    public string $notes = '';

    public bool $showAiSuggestions = false;

    /** @var array<int, array{task_id: int, reason: string, priority_score: int}> */
    public array $aiSuggestions = [];

    public bool $aiLoading = false;

    /** @var array<int, int> */
    public array $selectedYesterdayTasks = [];

    public function mount(): void
    {
        if ($this->date === '' || ! $this->isValidDate($this->date)) {
            $this->date = now()->toDateString();
        }

        $this->notes = $this->plan->notes ?? '';
    }

    /**
     * Validate that a string is a valid Y-m-d date.
     */
    private function isValidDate(string $value): bool
    {
        try {
            Carbon::createFromFormat('Y-m-d', $value);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    #[Computed]
    public function plan(): DailyPlan
    {
        return DailyPlan::getOrCreateForDate(Carbon::parse($this->date))
            ->load(['tasks' => fn ($q) => $q->with('project')->orderByPivot('sort_order')]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    #[Computed]
    public function availableTasks(): \Illuminate\Database\Eloquent\Collection
    {
        $planTaskIds = $this->plan->tasks->pluck('id')->toArray();

        return Activity::active()
            ->schedulable()
            ->whereNotIn('id', $planTaskIds)
            ->when($this->filterProjectId, fn ($q) => $q->where('project_id', $this->filterProjectId))
            ->with('project')
            ->orderBy('sort_order')
            ->limit(50)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\Project::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    public function updatedFilterProjectId(): void
    {
        unset($this->availableTasks);
    }

    #[Computed]
    public function completionRate(): float
    {
        return $this->plan->completionRate();
    }

    #[Computed]
    public function isToday(): bool
    {
        return Carbon::parse($this->date)->isToday();
    }

    #[Computed]
    public function isPast(): bool
    {
        return Carbon::parse($this->date)->isBefore(Carbon::today());
    }

    /**
     * @return \Illuminate\Support\Collection<int, Activity>|\Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    #[Computed]
    public function yesterdayIncompleteTasks(): \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection
    {
        $yesterday = Carbon::parse($this->date)->subDay();
        $yesterdayPlan = DailyPlan::query()->whereDate('date', $yesterday->toDateString())->first();

        if (! $yesterdayPlan) {
            return collect();
        }

        return $yesterdayPlan->incompleteTasks();
    }

    public function updatedDate(): void
    {
        if (! $this->isValidDate($this->date)) {
            $this->date = now()->toDateString();
        }

        unset($this->plan, $this->availableTasks, $this->completionRate, $this->isToday, $this->isPast, $this->yesterdayIncompleteTasks);

        $this->notes = $this->plan->notes ?? '';
    }

    public function updatedNotes(): void
    {
        if ($this->isPast) {
            return;
        }

        $this->plan->update(['notes' => $this->notes]);
    }

    public function addToPlan(int $taskId): void
    {
        if ($this->isPast) {
            return;
        }

        $task = Activity::active()->findOrFail($taskId);
        $maxOrder = $this->plan->tasks()->max('daily_plan_activity.sort_order') ?? -1;

        $this->plan->tasks()->syncWithoutDetaching([
            $taskId => ['sort_order' => $maxOrder + 1],
        ]);

        unset($this->plan, $this->availableTasks, $this->completionRate);

        Flux::toast(variant: 'success', heading: 'Task adicionada', text: $task->title);
    }

    public function removeFromPlan(int $taskId): void
    {
        if ($this->isPast) {
            return;
        }

        $task = Activity::findOrFail($taskId);
        $this->plan->tasks()->detach($taskId);

        unset($this->plan, $this->availableTasks, $this->completionRate);

        Flux::toast(variant: 'success', heading: 'Task removida', text: $task->title);
    }

    public function toggleTask(int $taskId): void
    {
        if ($this->isPast) {
            return;
        }

        $task = Activity::findOrFail($taskId);
        $pivot = $this->plan->tasks()->where('activity_id', $taskId)->first()?->pivot;

        if (! $pivot) {
            return;
        }

        if ($pivot->completed_at === null) {
            $task->markAsDone();
            $this->plan->tasks()->updateExistingPivot($taskId, ['completed_at' => now()]);
            Flux::toast(variant: 'success', heading: 'Task concluída', text: $task->title);
        } else {
            $task->update([
                'status' => ActivityStatus::Doing,
                'completed_at' => null,
            ]);
            $this->plan->tasks()->updateExistingPivot($taskId, ['completed_at' => null]);
            Flux::toast(variant: 'success', heading: 'Task reaberta', text: $task->title);
        }

        unset($this->plan, $this->availableTasks, $this->completionRate);

        $this->dispatch('task-updated');
    }

    public function handleSort(int|string $id, int $position): void
    {
        if ($this->isPast) {
            return;
        }

        $this->plan->tasks()->updateExistingPivot((int) $id, ['sort_order' => $position]);

        unset($this->plan);
    }

    public function carryOver(): void
    {
        if (! $this->isToday) {
            return;
        }

        $tasks = $this->yesterdayIncompleteTasks;
        $maxOrder = $this->plan->tasks()->max('daily_plan_activity.sort_order') ?? -1;

        foreach ($tasks as $index => $task) {
            $this->plan->tasks()->syncWithoutDetaching([
                $task->id => ['sort_order' => $maxOrder + 1 + $index],
            ]);
        }

        $this->selectedYesterdayTasks = [];

        unset($this->plan, $this->availableTasks, $this->completionRate, $this->yesterdayIncompleteTasks);

        Flux::toast(variant: 'success', heading: 'Tasks movidas', text: $tasks->count().' tasks adicionadas ao plano de hoje');
    }

    public function carryOverSelected(): void
    {
        if (! $this->isToday || empty($this->selectedYesterdayTasks)) {
            return;
        }

        $tasks = $this->yesterdayIncompleteTasks->whereIn('id', $this->selectedYesterdayTasks);
        $maxOrder = $this->plan->tasks()->max('daily_plan_activity.sort_order') ?? -1;

        foreach ($tasks as $index => $task) {
            $this->plan->tasks()->syncWithoutDetaching([
                $task->id => ['sort_order' => $maxOrder + 1 + $index],
            ]);
        }

        $count = $tasks->count();
        $this->selectedYesterdayTasks = [];

        unset($this->plan, $this->availableTasks, $this->completionRate, $this->yesterdayIncompleteTasks);

        Flux::toast(variant: 'success', heading: 'Tasks movidas', text: $count.' tasks adicionadas ao plano de hoje');
    }

    public function selectAllYesterday(): void
    {
        $this->selectedYesterdayTasks = $this->yesterdayIncompleteTasks->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function deselectAllYesterday(): void
    {
        $this->selectedYesterdayTasks = [];
    }

    public function moveToToday(int $taskId): void
    {
        if (! $this->plan->tasks()->where('activity_id', $taskId)->exists()) {
            return;
        }

        $task = Activity::findOrFail($taskId);
        $todayPlan = DailyPlan::getOrCreateForDate(Carbon::today());
        $maxOrder = $todayPlan->tasks()->max('daily_plan_activity.sort_order') ?? -1;

        $todayPlan->tasks()->syncWithoutDetaching([
            $taskId => ['sort_order' => $maxOrder + 1],
        ]);

        unset($this->plan, $this->availableTasks, $this->completionRate);

        Flux::toast(variant: 'success', heading: 'Task movida para hoje', text: $task->title);
    }

    #[On('task-updated')]
    #[On('task-created')]
    public function refreshPlan(): void
    {
        unset($this->plan, $this->availableTasks, $this->completionRate, $this->yesterdayIncompleteTasks);
    }

    /**
     * Check if AI features are enabled and configured.
     */
    public function isAiEnabled(): bool
    {
        return app(AiAssistantService::class)->isEnabled();
    }

    /**
     * Suggest a daily plan using the AI assistant.
     */
    public function suggestDailyPlan(): void
    {
        if (! $this->isAiEnabled()) {
            return;
        }

        $cacheKey = 'ai_daily_plan_'.(auth()->id() ?? 'guest');

        if (Cache::has($cacheKey)) {
            Flux::toast(variant: 'warning', heading: 'Aguarde', text: 'Aguarde 1 minuto antes de solicitar novas sugestões.');

            return;
        }

        $this->aiLoading = true;

        try {
            $availableTasks = Activity::query()
                ->whereIn('status', [ActivityStatus::Todo, ActivityStatus::Doing])
                ->with('project')
                ->get();

            $history = $this->buildCompletionHistory();

            $service = app(AiAssistantService::class);
            $result = $service->suggestDailyPlan($availableTasks, $history);

            $this->aiSuggestions = $result;
            $this->showAiSuggestions = true;

            Cache::put($cacheKey, true, now()->addMinute());
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', heading: 'Erro', text: 'Não foi possível gerar sugestões. Tente novamente.');
        } finally {
            $this->aiLoading = false;
        }
    }

    /**
     * Build completion history for the last 7 days.
     *
     * @return array<int, array{date: string, completion_rate: float}>
     */
    private function buildCompletionHistory(): array
    {
        $today = Carbon::today();
        $weekAgo = $today->copy()->subDays(6);

        $plans = DailyPlan::query()
            ->whereDate('date', '>=', $weekAgo)
            ->whereDate('date', '<=', $today)
            ->with('tasks')
            ->get()
            ->keyBy(fn (DailyPlan $plan): string => $plan->date->toDateString());

        $history = [];

        for ($i = 6; $i >= 0; $i--) {
            $dateString = $today->copy()->subDays($i)->toDateString();

            $history[] = [
                'date' => $dateString,
                'completion_rate' => $plans->get($dateString)?->completionRate() ?? 0,
            ];
        }

        return $history;
    }

    /**
     * Add a single AI suggestion to the daily plan.
     */
    public function addSuggestionToPlan(int $taskId): void
    {
        $task = Activity::query()
            ->whereIn('status', [ActivityStatus::Todo, ActivityStatus::Doing])
            ->find($taskId);

        if (! $task) {
            return;
        }

        $maxOrder = $this->plan->tasks()->max('daily_plan_activity.sort_order') ?? -1;

        $this->plan->tasks()->syncWithoutDetaching([
            $taskId => ['sort_order' => $maxOrder + 1],
        ]);

        $this->aiSuggestions = array_values(
            array_filter($this->aiSuggestions, fn (array $s): bool => $s['task_id'] !== $taskId)
        );

        unset($this->plan, $this->availableTasks, $this->completionRate);

        Flux::toast(variant: 'success', heading: 'Task adicionada', text: $task->title);
    }

    /**
     * Add all AI suggestions to the daily plan.
     */
    public function addAllSuggestionsToPlan(): void
    {
        $taskIds = array_column($this->aiSuggestions, 'task_id');

        $tasks = Activity::query()
            ->whereIn('status', [ActivityStatus::Todo, ActivityStatus::Doing])
            ->whereIn('id', $taskIds)
            ->get();

        $maxOrder = $this->plan->tasks()->max('daily_plan_activity.sort_order') ?? -1;

        foreach ($tasks as $index => $task) {
            $this->plan->tasks()->syncWithoutDetaching([
                $task->id => ['sort_order' => $maxOrder + 1 + $index],
            ]);
        }

        $this->aiSuggestions = [];
        $this->showAiSuggestions = false;

        unset($this->plan, $this->availableTasks, $this->completionRate);

        Flux::toast(variant: 'success', heading: 'Sugestões aplicadas', text: $tasks->count().' tasks adicionadas ao plano');
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-4 p-4 sm:p-6">
    {{-- Header --}}
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="xl">Daily Planner</flux:heading>

            <div class="flex items-center gap-2">
                @if ($this->isAiEnabled())
                    <flux:button
                        variant="subtle"
                        icon="sparkles"
                        wire:click="suggestDailyPlan"
                        wire:loading.attr="disabled"
                        wire:target="suggestDailyPlan"
                        size="sm"
                    >
                        <span wire:loading.remove wire:target="suggestDailyPlan" class="hidden sm:inline">Sugerir plano</span>
                        <span wire:loading wire:target="suggestDailyPlan" class="hidden sm:inline">Analisando...</span>
                    </flux:button>
                @endif

                <flux:date-picker wire:model.live="date" with-today locale="pt-BR" />
            </div>
        </div>

        @if ($this->isPast)
            <flux:badge color="zinc" icon="lock-closed" class="w-fit">Plano passado (somente leitura)</flux:badge>
        @endif
    </div>

    {{-- Date display + Progress --}}
    <div class="flex flex-col gap-3 rounded-xl border border-zinc-700 bg-zinc-900/50 p-3 sm:p-4">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
            <flux:text class="text-sm capitalize text-zinc-300">
                {{ Carbon::parse($this->date)->translatedFormat('l, d \d\e F') }}
            </flux:text>

            @if ($this->plan->tasks->isNotEmpty())
                <flux:text class="text-sm text-zinc-400">
                    {{ $this->plan->tasks->filter(fn ($t) => $t->pivot->completed_at !== null)->count() }}/{{ $this->plan->tasks->count() }} concluídas
                </flux:text>
            @endif
        </div>

        @if ($this->plan->tasks->isNotEmpty())
            <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-700">
                <div
                    class="h-full rounded-full bg-emerald-500 transition-all duration-500"
                    style="width: {{ $this->completionRate }}%"
                ></div>
            </div>
        @endif
    </div>

    {{-- Two-column layout --}}
    <div class="grid flex-1 gap-4 md:grid-cols-5">
        {{-- Left column: Plano do Dia (3 cols) --}}
        <div class="flex flex-col gap-3 md:col-span-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <flux:heading size="sm">Plano do Dia</flux:heading>
                    <flux:badge size="sm" color="zinc">{{ $this->plan->tasks->count() }}</flux:badge>
                </div>
            </div>

            <div
                x-data="{
                    completedCollapsed: localStorage.getItem('daily-completed-collapsed') === 'true',
                    toggleCompleted() {
                        this.completedCollapsed = !this.completedCollapsed;
                        localStorage.setItem('daily-completed-collapsed', this.completedCollapsed);
                    }
                }"
                class="rounded-xl border border-zinc-700 bg-zinc-900/50"
            >
                @if ($this->plan->tasks->isNotEmpty())
                    @php
                        $pendingTasks = $this->plan->tasks->filter(fn ($t) => $t->pivot->completed_at === null);
                        $completedTasks = $this->plan->tasks->filter(fn ($t) => $t->pivot->completed_at !== null);
                    @endphp

                    {{-- Pending Tasks --}}
                    @if ($pendingTasks->isNotEmpty())
                        <ul wire:sort="handleSort" class="divide-y divide-zinc-700/50">
                            @foreach ($pendingTasks as $task)
                                <li wire:key="task-{{ $task->id }}" wire:sort:item="{{ $task->id }}" class="flex items-center gap-3 px-4 py-3">
                                    {{-- Drag handle --}}
                                    @unless ($this->isPast)
                                        <div wire:sort:handle class="shrink-0 cursor-grab text-zinc-600 hover:text-zinc-400">
                                            <flux:icon name="grip-vertical" class="size-4" />
                                        </div>
                                    @endunless

                                    <div wire:sort:ignore class="flex min-w-0 flex-1 items-center gap-3">
                                        {{-- Checkbox --}}
                                        @if (! $this->isPast)
                                            <flux:checkbox
                                                wire:click="toggleTask({{ $task->id }})"
                                                :checked="false"
                                            />
                                        @else
                                            <flux:checkbox
                                                :checked="false"
                                                disabled
                                            />
                                        @endif

                                        {{-- Task info --}}
                                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                                            <span class="truncate text-sm font-medium text-zinc-200">
                                                {{ $task->title }}
                                            </span>

                                            <div class="flex flex-wrap items-center gap-1.5">
                                                {{-- Project badge --}}
                                                @if ($task->project)
                                                    <div class="flex items-center gap-1 border-l-2 pl-1.5" style="border-color: {{ $task->project->color }}">
                                                        <span class="text-xs">{{ $task->project->emoji }}</span>
                                                        <span class="truncate text-xs text-zinc-400">{{ $task->project->name }}</span>
                                                    </div>
                                                @endif

                                                {{-- Priority badge --}}
                                                @if ($task->priority)
                                                    <flux:badge size="sm" color="{{ $task->priority->color() }}" icon="{{ $task->priority->icon() }}">
                                                        {{ $task->priority->label() }}
                                                    </flux:badge>
                                                @endif

                                                {{-- Estimate badge --}}
                                                @if ($task->estimated_minutes)
                                                    <flux:badge size="sm" color="zinc" icon="clock">
                                                        {{ $task->estimated_minutes }}m
                                                    </flux:badge>
                                                @endif

                                                {{-- Overdue badge --}}
                                                @if ($task->isOverdue())
                                                    <flux:badge size="sm" color="red" icon="exclamation-triangle">
                                                        {{ $task->due_date->diffForHumans() }}
                                                    </flux:badge>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Actions --}}
                                        @if ($this->isPast)
                                            <flux:button
                                                wire:click="moveToToday({{ $task->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="moveToToday({{ $task->id }})"
                                                size="sm"
                                                variant="ghost"
                                                icon="arrow-right"
                                                class="shrink-0"
                                            >
                                                Hoje
                                            </flux:button>
                                        @else
                                            <flux:button
                                                wire:click="removeFromPlan({{ $task->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="removeFromPlan({{ $task->id }})"
                                                size="sm"
                                                variant="ghost"
                                                icon="x-mark"
                                                class="shrink-0 text-zinc-500 hover:text-red-400"
                                            />
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Completed Tasks (Collapsible) --}}
                    @if ($completedTasks->isNotEmpty())
                        <div class="border-t border-zinc-700/50">
                            {{-- Collapsible Header --}}
                            <button
                                type="button"
                                @click="toggleCompleted()"
                                class="flex w-full items-center justify-between px-4 py-2.5 text-left transition-colors hover:bg-zinc-800/50"
                            >
                                <div class="flex items-center gap-2">
                                    <flux:icon name="check-circle" class="size-4 text-emerald-500" />
                                    <span class="text-sm font-medium text-zinc-400">Concluídas</span>
                                    <flux:badge size="sm" color="emerald">{{ $completedTasks->count() }}</flux:badge>
                                </div>
                                <flux:icon
                                    name="chevron-down"
                                    class="size-4 text-zinc-500 transition-transform duration-200"
                                    x-bind:class="completedCollapsed ? '' : 'rotate-180'"
                                />
                            </button>

                            {{-- Collapsible Content --}}
                            <div
                                x-show="!completedCollapsed"
                                x-collapse
                                x-cloak
                            >
                                <ul wire:sort="handleSort" class="divide-y divide-zinc-700/30 border-t border-zinc-700/30">
                                    @foreach ($completedTasks as $task)
                                        <li wire:key="task-{{ $task->id }}" wire:sort:item="{{ $task->id }}" class="flex items-center gap-3 px-4 py-3 opacity-60">
                                            {{-- Drag handle --}}
                                            @unless ($this->isPast)
                                                <div wire:sort:handle class="shrink-0 cursor-grab text-zinc-600 hover:text-zinc-400">
                                                    <flux:icon name="grip-vertical" class="size-4" />
                                                </div>
                                            @endunless

                                            <div wire:sort:ignore class="flex min-w-0 flex-1 items-center gap-3">
                                                {{-- Checkbox --}}
                                                @if (! $this->isPast)
                                                    <flux:checkbox
                                                        wire:click="toggleTask({{ $task->id }})"
                                                        :checked="true"
                                                    />
                                                @else
                                                    <flux:checkbox
                                                        :checked="true"
                                                        disabled
                                                    />
                                                @endif

                                                {{-- Task info --}}
                                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                                    <span class="truncate text-sm font-medium text-zinc-500 line-through">
                                                        {{ $task->title }}
                                                    </span>

                                                    <div class="flex flex-wrap items-center gap-1.5">
                                                        {{-- Project badge --}}
                                                        @if ($task->project)
                                                            <div class="flex items-center gap-1 border-l-2 pl-1.5" style="border-color: {{ $task->project->color }}">
                                                                <span class="text-xs">{{ $task->project->emoji }}</span>
                                                                <span class="truncate text-xs text-zinc-500">{{ $task->project->name }}</span>
                                                            </div>
                                                        @endif

                                                        {{-- Priority badge --}}
                                                        @if ($task->priority)
                                                            <flux:badge size="sm" color="zinc" icon="{{ $task->priority->icon() }}">
                                                                {{ $task->priority->label() }}
                                                            </flux:badge>
                                                        @endif
                                                    </div>
                                                </div>

                                                {{-- Actions --}}
                                                @unless ($this->isPast)
                                                    <flux:button
                                                        wire:click="removeFromPlan({{ $task->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="removeFromPlan({{ $task->id }})"
                                                        size="sm"
                                                        variant="ghost"
                                                        icon="x-mark"
                                                        class="shrink-0 text-zinc-500 hover:text-red-400"
                                                    />
                                                @endunless
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="py-12 text-center">
                        <flux:icon name="calendar" class="mx-auto mb-2 size-8 text-zinc-600" />
                        <flux:text class="text-sm text-zinc-500">Nenhuma task no plano</flux:text>
                    </div>
                @endif
            </div>

            {{-- Notes --}}
            <div class="mt-2">
                <flux:textarea
                    wire:model.blur="notes"
                    label="Notas do dia"
                    placeholder="Notas do dia..."
                    rows="auto"
                    :readonly="$this->isPast"
                />
            </div>
        </div>

        {{-- Right column: Tasks de Ontem + Tasks Disponíveis (2 cols) --}}
        @unless ($this->isPast)
            <div class="flex flex-col gap-3 md:col-span-2">
                {{-- Tasks de Ontem --}}
                @if ($this->isToday && $this->yesterdayIncompleteTasks->isNotEmpty())
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex shrink-0 items-center gap-2">
                            <flux:heading size="sm">Tasks de Ontem</flux:heading>
                            <flux:badge size="sm" color="amber">{{ $this->yesterdayIncompleteTasks->count() }}</flux:badge>
                        </div>

                        <div class="flex items-center gap-2">
                            @if (count($selectedYesterdayTasks) > 0)
                                <flux:button
                                    wire:click="carryOverSelected"
                                    wire:loading.attr="disabled"
                                    wire:target="carryOverSelected"
                                    size="sm"
                                    variant="primary"
                                    icon="plus"
                                >
                                    Adicionar {{ count($selectedYesterdayTasks) }}
                                </flux:button>
                            @else
                                <flux:button
                                    wire:click="carryOver"
                                    wire:loading.attr="disabled"
                                    wire:target="carryOver"
                                    size="sm"
                                    variant="subtle"
                                    icon="plus"
                                >
                                    Adicionar todas
                                </flux:button>
                            @endif
                        </div>
                    </div>

                    <div class="rounded-xl border border-amber-700/50 bg-amber-950/20">
                        {{-- Select All Bar --}}
                        <div class="flex items-center gap-3 border-b border-amber-700/30 px-4 py-2">
                            @php
                                $allYesterdaySelected = count($selectedYesterdayTasks) === $this->yesterdayIncompleteTasks->count() && $this->yesterdayIncompleteTasks->count() > 0;
                                $someYesterdaySelected = count($selectedYesterdayTasks) > 0 && count($selectedYesterdayTasks) < $this->yesterdayIncompleteTasks->count();
                            @endphp
                            <flux:checkbox
                                :checked="$allYesterdaySelected"
                                :indeterminate="$someYesterdaySelected"
                                wire:click="{{ $allYesterdaySelected ? 'deselectAllYesterday' : 'selectAllYesterday' }}"
                            />
                            <span class="text-xs text-zinc-400">
                                @if (count($selectedYesterdayTasks) > 0)
                                    {{ count($selectedYesterdayTasks) }} selecionada(s)
                                @else
                                    Selecionar todas
                                @endif
                            </span>
                        </div>

                        <ul class="divide-y divide-amber-700/30">
                            @foreach ($this->yesterdayIncompleteTasks as $task)
                                <li wire:key="yesterday-{{ $task->id }}" class="flex items-center gap-3 px-4 py-3">
                                    <flux:checkbox wire:model.live="selectedYesterdayTasks" value="{{ $task->id }}" />

                                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                                        <span class="truncate text-sm font-medium text-zinc-200">{{ $task->title }}</span>

                                        <div class="flex flex-wrap items-center gap-1.5">
                                            {{-- Project badge --}}
                                            @if ($task->project)
                                                <div class="flex items-center gap-1 border-l-2 pl-1.5" style="border-color: {{ $task->project->color }}">
                                                    <span class="text-xs">{{ $task->project->emoji }}</span>
                                                    <span class="truncate text-xs text-zinc-400">{{ $task->project->name }}</span>
                                                </div>
                                            @endif

                                            {{-- Priority badge --}}
                                            @if ($task->priority)
                                                <flux:badge size="sm" color="{{ $task->priority->color() }}" icon="{{ $task->priority->icon() }}">
                                                    {{ $task->priority->label() }}
                                                </flux:badge>
                                            @endif

                                            {{-- Status badge --}}
                                            <flux:badge size="sm" color="{{ $task->status->color() }}" icon="{{ $task->status->icon() }}">
                                                {{ $task->status->label() }}
                                            </flux:badge>
                                        </div>
                                    </div>

                                    <flux:button
                                        wire:click="addToPlan({{ $task->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="addToPlan({{ $task->id }})"
                                        size="sm"
                                        variant="ghost"
                                        icon="plus"
                                        class="shrink-0"
                                    />
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Tasks Disponíveis --}}
                <div class="flex items-center justify-between gap-2">
                    <div class="flex shrink-0 items-center gap-2">
                        <flux:heading size="sm">Tasks Disponíveis</flux:heading>
                        <flux:badge size="sm" color="zinc">{{ $this->availableTasks->count() }}</flux:badge>
                    </div>

                    <flux:select wire:model.live="filterProjectId" size="sm" class="min-w-0 max-w-36">
                        <flux:select.option value="">Todos</flux:select.option>
                        @foreach ($this->projects as $project)
                            <flux:select.option value="{{ $project->id }}">
                                {{ $project->emoji }} {{ $project->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="rounded-xl border border-zinc-700 bg-zinc-900/50">
                    @if ($this->availableTasks->isNotEmpty())
                        <ul class="divide-y divide-zinc-700/50">
                            @foreach ($this->availableTasks as $task)
                                <li wire:key="available-{{ $task->id }}" class="flex items-center gap-3 px-4 py-3">
                                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                                        <span class="truncate text-sm font-medium text-zinc-200">{{ $task->title }}</span>

                                        <div class="flex flex-wrap items-center gap-1.5">
                                            {{-- Project badge --}}
                                            @if ($task->project)
                                                <div class="flex items-center gap-1 border-l-2 pl-1.5" style="border-color: {{ $task->project->color }}">
                                                    <span class="text-xs">{{ $task->project->emoji }}</span>
                                                    <span class="truncate text-xs text-zinc-400">{{ $task->project->name }}</span>
                                                </div>
                                            @endif

                                            {{-- Priority badge --}}
                                            @if ($task->priority)
                                                <flux:badge size="sm" color="{{ $task->priority->color() }}" icon="{{ $task->priority->icon() }}">
                                                    {{ $task->priority->label() }}
                                                </flux:badge>
                                            @endif

                                            {{-- Status badge --}}
                                            <flux:badge size="sm" color="{{ $task->status->color() }}" icon="{{ $task->status->icon() }}">
                                                {{ $task->status->label() }}
                                            </flux:badge>
                                        </div>
                                    </div>

                                    <flux:button
                                        wire:click="addToPlan({{ $task->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="addToPlan({{ $task->id }})"
                                        size="sm"
                                        variant="ghost"
                                        icon="plus"
                                        class="shrink-0"
                                    />
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="py-12 text-center">
                            <flux:icon name="check-circle" class="mx-auto mb-2 size-8 text-zinc-600" />
                            <flux:text class="text-sm text-zinc-500">Todas as tasks ativas já estão no plano</flux:text>
                        </div>
                    @endif
                </div>
            </div>
        @endunless
    </div>

    {{-- AI Suggestions Modal --}}
    @if ($this->isAiEnabled())
        <flux:modal wire:model.self="showAiSuggestions" class="md:w-[32rem]">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Sugestões do AI Coach</flux:heading>
                    <flux:text class="mt-1">Baseado nas suas tasks e histórico de produtividade.</flux:text>
                </div>

                @if ($aiLoading)
                    <div class="space-y-3">
                        @for ($i = 0; $i < 3; $i++)
                            <div class="rounded-lg border border-zinc-700 p-3">
                                <flux:skeleton class="mb-2 h-4 w-3/4" />
                                <flux:skeleton class="mb-2 h-3 w-full" />
                                <flux:skeleton class="h-3 w-1/4" />
                            </div>
                        @endfor
                    </div>
                @elseif (empty($aiSuggestions))
                    <div class="py-8 text-center">
                        <flux:icon name="sparkles" class="mx-auto mb-2 size-8 text-zinc-600" />
                        <flux:text class="text-sm text-zinc-500">Nenhuma sugestão disponível no momento.</flux:text>
                    </div>
                @else
                    @php
                        $suggestionTaskIds = array_column($aiSuggestions, 'task_id');
                        $suggestionTasks = \App\Models\Activity::whereIn('id', $suggestionTaskIds)->get()->keyBy('id');
                    @endphp

                    <div class="max-h-96 space-y-3 overflow-y-auto">
                        @foreach ($aiSuggestions as $suggestion)
                            @php
                                $task = $suggestionTasks->get($suggestion['task_id']);
                                $score = $suggestion['priority_score'] ?? 0;
                                $scoreColor = $score >= 80 ? 'red' : ($score >= 50 ? 'amber' : 'blue');
                            @endphp

                            @if ($task)
                                <div wire:key="suggestion-{{ $suggestion['task_id'] }}" class="rounded-lg border border-zinc-700 p-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="truncate text-sm font-medium text-zinc-200">{{ $task->title }}</span>
                                                <flux:badge size="sm" color="{{ $scoreColor }}">{{ $score }}%</flux:badge>
                                            </div>
                                            <flux:text class="mt-1 text-xs text-zinc-400">{{ $suggestion['reason'] }}</flux:text>
                                        </div>

                                        <flux:button
                                            wire:click="addSuggestionToPlan({{ $task->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="addSuggestionToPlan({{ $task->id }})"
                                            size="sm"
                                            variant="ghost"
                                            icon="plus"
                                            class="shrink-0"
                                        />
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <div class="flex justify-end gap-2 border-t border-zinc-700 pt-4">
                        <flux:modal.close>
                            <flux:button variant="ghost">Fechar</flux:button>
                        </flux:modal.close>
                        <flux:button
                            wire:click="addAllSuggestionsToPlan"
                            wire:loading.attr="disabled"
                            wire:target="addAllSuggestionsToPlan"
                            variant="primary"
                            icon="plus"
                        >
                            Adicionar todas
                        </flux:button>
                    </div>
                @endif
            </div>
        </flux:modal>
    @endif
</div>
