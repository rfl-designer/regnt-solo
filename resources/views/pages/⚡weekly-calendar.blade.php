<?php

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\Project;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'week')]
    public string $weekStart = '';

    #[Url(as: 'weekends')]
    public bool $showWeekends = false;

    public function mount(): void
    {
        if ($this->weekStart === '' || ! $this->isValidDate($this->weekStart)) {
            $this->weekStart = $this->getMonday(Carbon::today())->toDateString();
        }
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

    /**
     * Get the Monday of the week containing the given date.
     */
    private function getMonday(Carbon $date): Carbon
    {
        return $date->copy()->startOfWeek(Carbon::MONDAY);
    }

    /**
     * Get the current week start date as Carbon.
     */
    #[Computed]
    public function weekStartDate(): Carbon
    {
        return Carbon::parse($this->weekStart);
    }

    /**
     * Get the days to display based on showWeekends setting.
     *
     * @return Collection<int, array{date: Carbon, plan: DailyPlan, isPast: bool, isToday: bool}>
     */
    #[Computed]
    public function days(): Collection
    {
        $start = $this->showWeekends
            ? $this->weekStartDate->copy()->subDay() // Sunday
            : $this->weekStartDate; // Monday

        $daysCount = $this->showWeekends ? 7 : 5;
        $today = Carbon::today();

        return collect(range(0, $daysCount - 1))->map(function (int $offset) use ($start, $today): array {
            $date = $start->copy()->addDays($offset);
            $isPast = $date->isBefore($today);

            $plan = $isPast
                ? DailyPlan::whereDate('date', $date)->first()
                : DailyPlan::getOrCreateForDate($date);

            $plan?->load(['tasks' => fn ($q) => $q->with('project')->orderByPivot('sort_order')]);

            return [
                'date' => $date,
                'plan' => $plan,
                'isPast' => $isPast,
                'isToday' => $date->isToday(),
            ];
        });
    }

    /**
     * Get tasks not planned for any day in the current week.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    #[Computed]
    public function availableTasks(): \Illuminate\Database\Eloquent\Collection
    {
        $plannedTaskIds = $this->days->flatMap(fn (array $day): array => $day['plan']?->tasks->pluck('id')->toArray() ?? [])->unique()->toArray();

        return Activity::active()
            ->whereIn('type', [ActivityType::Issue, ActivityType::Task])
            ->whereNotIn('id', $plannedTaskIds)
            ->with('project')
            ->orderBy('sort_order')
            ->limit(30)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    /**
     * Calculate total estimated minutes for a day.
     */
    public function getDayLoad(?DailyPlan $plan): int
    {
        return $plan?->tasks->sum('estimated_minutes') ?? 0;
    }

    /**
     * Get load color based on estimated minutes.
     */
    public function getLoadColor(int $minutes): string
    {
        if ($minutes === 0) {
            return 'zinc';
        }
        if ($minutes <= 240) { // 4 hours
            return 'emerald';
        }
        if ($minutes <= 360) { // 6 hours
            return 'amber';
        }

        return 'red'; // > 6 hours
    }

    public function previousWeek(): void
    {
        $this->weekStart = $this->weekStartDate->copy()->subWeek()->toDateString();
        $this->resetComputedProperties();
    }

    public function nextWeek(): void
    {
        $this->weekStart = $this->weekStartDate->copy()->addWeek()->toDateString();
        $this->resetComputedProperties();
    }

    public function goToToday(): void
    {
        $this->weekStart = $this->getMonday(Carbon::today())->toDateString();
        $this->resetComputedProperties();
    }

    public function toggleWeekends(): void
    {
        $this->showWeekends = ! $this->showWeekends;
        $this->resetComputedProperties();
    }

    /**
     * Handle drag between days.
     */
    public function handleSort(int|string $id, int $position, string $groupId): void
    {
        $taskId = (int) $id;

        // If dropped back to pool, remove from all plans in the week
        if ($groupId === 'pool') {
            $task = Activity::findOrFail($taskId);

            foreach ($this->days as $day) {
                if ($day['plan']?->tasks->contains('id', $taskId)) {
                    $day['plan']->tasks()->detach($taskId);
                }
            }

            $this->resetComputedProperties();

            Flux::toast(variant: 'success', heading: 'Task removida do plano', text: $task->title);

            return;
        }

        $targetDate = Carbon::parse($groupId);

        // Don't allow changes to past dates
        if ($targetDate->isBefore(Carbon::today())) {
            Flux::toast(variant: 'warning', heading: 'Ação bloqueada', text: 'Não é possível modificar dias passados.');

            return;
        }

        $task = Activity::findOrFail($taskId);
        $targetPlan = DailyPlan::getOrCreateForDate($targetDate);

        // Find which plan currently has this task
        $currentPlan = null;
        foreach ($this->days as $day) {
            if ($day['plan']?->tasks->contains('id', $taskId)) {
                $currentPlan = $day['plan'];
                break;
            }
        }

        // Remove from current plan if exists and different from target
        if ($currentPlan && $currentPlan->id !== $targetPlan->id) {
            $currentPlan->tasks()->detach($taskId);
        }

        // Add or update in target plan
        if ($targetPlan->tasks()->where('activity_id', $taskId)->exists()) {
            $targetPlan->tasks()->updateExistingPivot($taskId, ['sort_order' => $position]);
        } else {
            $targetPlan->tasks()->attach($taskId, ['sort_order' => $position]);
        }

        $this->resetComputedProperties();

        Flux::toast(variant: 'success', heading: 'Task movida', text: $task->title);
    }

    /**
     * Add task to a specific day.
     */
    public function addToPlan(int $taskId, string $dateString): void
    {
        $date = Carbon::parse($dateString);

        if ($date->isBefore(Carbon::today())) {
            return;
        }

        $task = Activity::active()->findOrFail($taskId);
        $plan = DailyPlan::getOrCreateForDate($date);
        $maxOrder = $plan->tasks()->max('daily_plan_activity.sort_order') ?? -1;

        $plan->tasks()->syncWithoutDetaching([
            $taskId => ['sort_order' => $maxOrder + 1],
        ]);

        $this->resetComputedProperties();

        Flux::toast(variant: 'success', heading: 'Task adicionada', text: $task->title);
    }

    /**
     * Remove task from a specific day.
     */
    public function removeFromPlan(int $taskId, string $dateString): void
    {
        $date = Carbon::parse($dateString);

        if ($date->isBefore(Carbon::today())) {
            return;
        }

        $task = Activity::findOrFail($taskId);
        $plan = DailyPlan::query()->whereDate('date', $date->toDateString())->first();

        if ($plan) {
            $plan->tasks()->detach($taskId);
        }

        $this->resetComputedProperties();

        Flux::toast(variant: 'success', heading: 'Task removida', text: $task->title);
    }

    #[On('task-updated')]
    #[On('task-created')]
    public function refreshCalendar(): void
    {
        $this->resetComputedProperties();
    }

    private function resetComputedProperties(): void
    {
        unset($this->weekStartDate, $this->days, $this->availableTasks, $this->projects);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-4 p-6">
    {{-- Breadcrumb --}}
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('daily') }}" wire:navigate icon="calendar-days">
            Daily
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>
            Semana
        </flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
            <flux:heading size="xl">Calendário Semanal</flux:heading>
            <flux:badge color="zinc" icon="calendar-days">
                {{ $this->weekStartDate->translatedFormat('d M') }} - {{ $this->weekStartDate->copy()->addDays($showWeekends ? 6 : 4)->translatedFormat('d M Y') }}
            </flux:badge>
        </div>

        <div class="flex items-center gap-3">
            {{-- Week navigation --}}
            <div class="flex items-center gap-1">
                <flux:button
                    wire:click="previousWeek"
                    size="sm"
                    variant="ghost"
                    icon="chevron-left"
                />
                <flux:button
                    wire:click="goToToday"
                    size="sm"
                    variant="subtle"
                >
                    Hoje
                </flux:button>
                <flux:button
                    wire:click="nextWeek"
                    size="sm"
                    variant="ghost"
                    icon="chevron-right"
                />
            </div>

            {{-- Weekend toggle --}}
            <flux:button
                wire:click="toggleWeekends"
                size="sm"
                :variant="$showWeekends ? 'primary' : 'subtle'"
                :icon="$showWeekends ? 'calendar' : 'briefcase'"
                :tooltip="$showWeekends ? 'Clique para mostrar apenas dias úteis (Seg-Sex)' : 'Clique para incluir fins de semana (Dom-Sáb)'"
            >
                <span class="hidden sm:inline">{{ $showWeekends ? 'Dom-Sáb' : 'Seg-Sex' }}</span>
                <span class="sm:hidden">{{ $showWeekends ? '7d' : '5d' }}</span>
            </flux:button>

            {{-- Mobile tasks pool button --}}
            <flux:modal.trigger name="mobile-tasks-pool" class="lg:hidden">
                <flux:button size="sm" variant="subtle" icon="clipboard-document-list">
                    <span class="hidden sm:inline">Disponíveis</span>
                    <flux:badge size="sm" color="zinc" class="ml-1">{{ $this->availableTasks->count() }}</flux:badge>
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    {{-- Legend --}}
    <div
        x-data="{ showLegend: false }"
        class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-lg border border-zinc-800 bg-zinc-900/30 px-4 py-2 text-xs text-zinc-400"
    >
        <button
            @click="showLegend = !showLegend"
            class="flex items-center gap-1.5 font-medium text-zinc-300 hover:text-white"
        >
            <flux:icon name="question-mark-circle" class="size-4" />
            <span>Legenda</span>
            <flux:icon
                name="chevron-down"
                class="size-3 transition-transform"
                ::class="showLegend && 'rotate-180'"
            />
        </button>

        <div
            x-show="showLegend"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="flex flex-wrap items-center gap-x-6 gap-y-2"
        >
            {{-- Load colors --}}
            <div class="flex items-center gap-3">
                <span class="text-zinc-500">Carga:</span>
                <div class="flex items-center gap-1">
                    <div class="size-2.5 rounded-full bg-emerald-500"></div>
                    <span>≤4h</span>
                </div>
                <div class="flex items-center gap-1">
                    <div class="size-2.5 rounded-full bg-amber-500"></div>
                    <span>≤6h</span>
                </div>
                <div class="flex items-center gap-1">
                    <div class="size-2.5 rounded-full bg-red-500"></div>
                    <span>>6h</span>
                </div>
            </div>

            {{-- Icons --}}
            <div class="flex items-center gap-3">
                <span class="text-zinc-500">Ícones:</span>
                <div class="flex items-center gap-1">
                    <flux:icon name="lock-closed" class="size-3.5 text-zinc-500" />
                    <span>Dia passado</span>
                </div>
                <div class="flex items-center gap-1">
                    <flux:icon name="check-circle" class="size-3.5 text-emerald-500" />
                    <span>Concluída</span>
                </div>
                <div class="flex items-center gap-1">
                    <flux:icon name="grip-vertical" class="size-3.5 text-zinc-500" />
                    <span>Arrastar</span>
                </div>
            </div>

            {{-- Toggle explanation --}}
            <div class="flex items-center gap-3">
                <span class="text-zinc-500">Toggle:</span>
                <div class="flex items-center gap-1">
                    <flux:icon name="briefcase" class="size-3.5 text-zinc-400" />
                    <span>Seg-Sex</span>
                </div>
                <div class="flex items-center gap-1">
                    <flux:icon name="calendar" class="size-3.5 text-zinc-400" />
                    <span>Dom-Sáb</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Calendar Grid --}}
    <div class="flex flex-1 gap-4 overflow-hidden">
        {{-- Available Tasks Pool (Fixed - hidden on small screens) --}}
        <div class="hidden w-72 shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50 lg:flex">
            <div class="flex items-center justify-between border-b border-zinc-700 px-4 py-3">
                <div class="flex items-center gap-2">
                    <flux:icon name="clipboard-document-list" class="size-5 text-zinc-400" />
                    <flux:heading size="sm">Disponíveis</flux:heading>
                </div>
                <flux:badge size="sm" color="zinc">{{ $this->availableTasks->count() }}</flux:badge>
            </div>

            <div class="flex-1 overflow-y-auto p-2">
                <ul
                    wire:sort="handleSort"
                    wire:sort:group="weekly-tasks"
                    wire:sort:group-id="pool"
                    class="flex min-h-[4rem] flex-col gap-2"
                >
                    @forelse ($this->availableTasks as $task)
                        <li wire:key="available-{{ $task->id }}" wire:sort:item="{{ $task->id }}">
                            <div class="group rounded-lg border border-zinc-700 bg-zinc-800 p-2 transition hover:border-zinc-500">
                                <div class="flex items-start gap-2">
                                    <div wire:sort:handle class="mt-0.5 shrink-0 cursor-grab text-zinc-600 hover:text-zinc-400">
                                        <flux:icon name="grip-vertical" class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1" wire:sort:ignore>
                                        <span class="line-clamp-2 text-xs font-medium text-zinc-200">{{ $task->title }}</span>

                                        <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                            @if ($task->project)
                                                <div class="flex items-center gap-1 border-l-2 pl-1" style="border-color: {{ $task->project->color }}">
                                                    <span class="text-[10px]">{{ $task->project->emoji }}</span>
                                                    <span class="truncate text-[10px] text-zinc-400">{{ Str::limit($task->project->name, 10) }}</span>
                                                </div>
                                            @endif

                                            @if ($task->priority)
                                                <flux:badge size="sm" color="{{ $task->priority->color() }}">
                                                    {{ $task->priority->label() }}
                                                </flux:badge>
                                            @endif

                                            @if ($task->estimated_minutes)
                                                <flux:badge size="sm" color="zinc">
                                                    {{ $task->estimated_minutes }}m
                                                </flux:badge>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="py-8 text-center text-xs text-zinc-600">
                            Todas as tasks estão planejadas
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- Day Columns (Scrollable) --}}
        <div
            class="relative flex flex-1 gap-3 overflow-x-auto scroll-smooth pb-2"
            x-data="{
                showLeftFade: false,
                showRightFade: true,
                checkScroll() {
                    this.showLeftFade = this.$el.scrollLeft > 20;
                    this.showRightFade = this.$el.scrollLeft < (this.$el.scrollWidth - this.$el.clientWidth - 20);
                },
                scrollToToday() {
                    const today = this.$el.querySelector('[data-today]');
                    if (today) {
                        today.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
                    }
                }
            }"
            x-init="$nextTick(() => { checkScroll(); scrollToToday(); })"
            x-on:scroll.throttle.100ms="checkScroll()"
        >
            {{-- Left fade indicator --}}
            <div
                x-show="showLeftFade"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="pointer-events-none absolute left-0 top-0 z-10 h-full w-8 bg-gradient-to-r from-zinc-950 to-transparent"
            ></div>

            {{-- Right fade indicator --}}
            <div
                x-show="showRightFade"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="pointer-events-none absolute right-0 top-0 z-10 h-full w-8 bg-gradient-to-l from-zinc-950 to-transparent"
            ></div>

        @foreach ($this->days as $day)
            @php
                $date = $day['date'];
                $plan = $day['plan'];
                $isPast = $day['isPast'];
                $isToday = $day['isToday'];
                $dayLoad = $this->getDayLoad($plan);
                $loadColor = $this->getLoadColor($dayLoad);
            @endphp

            <div
                @if($isToday) data-today @endif
                class="flex w-56 min-w-[14rem] shrink-0 flex-col rounded-xl border sm:w-64 md:w-72 lg:w-80 {{ $isToday ? 'border-emerald-600 ring-1 ring-emerald-600/30' : ($isPast ? 'border-zinc-800' : 'border-zinc-700') }} {{ $isPast ? 'bg-zinc-900/30 opacity-75' : 'bg-zinc-900/50' }}"
            >
                {{-- Day Header --}}
                <div class="flex flex-col gap-1 border-b {{ $isToday ? 'border-emerald-600' : 'border-zinc-700' }} px-3 py-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium capitalize {{ $isToday ? 'text-emerald-400' : ($isPast ? 'text-zinc-500' : 'text-zinc-300') }}">
                                {{ $date->translatedFormat('D') }}
                            </span>
                            <span class="text-lg font-bold {{ $isToday ? 'text-emerald-400' : ($isPast ? 'text-zinc-500' : 'text-zinc-200') }}">
                                {{ $date->format('d') }}
                            </span>
                        </div>

                        @if ($isToday)
                            <flux:badge size="sm" color="emerald">Hoje</flux:badge>
                        @elseif ($isPast)
                            <flux:icon name="lock-closed" class="size-4 text-zinc-600" />
                        @endif
                    </div>

                    {{-- Day Load Indicator --}}
                    <div class="flex items-center gap-2">
                        <flux:badge size="sm" color="{{ $loadColor }}">
                            {{ $plan?->tasks->count() ?? 0 }} tasks
                        </flux:badge>
                        @if ($dayLoad > 0)
                            <span class="text-[10px] text-zinc-500">
                                {{ floor($dayLoad / 60) }}h{{ $dayLoad % 60 > 0 ? ($dayLoad % 60).'m' : '' }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Tasks List --}}
                <div class="flex-1 overflow-y-auto p-2">
                    <ul
                        wire:sort="handleSort"
                        wire:sort:group="weekly-tasks"
                        wire:sort:group-id="{{ $date->toDateString() }}"
                        class="flex min-h-[4rem] flex-col gap-2"
                    >
                        @forelse ($plan?->tasks ?? collect() as $task)
                            @php
                                $isCompleted = $task->pivot->completed_at !== null;
                            @endphp

                            <li wire:key="task-{{ $plan->id }}-{{ $task->id }}" wire:sort:item="{{ $task->id }}">
                                <div
                                    class="group rounded-lg border {{ $isCompleted ? 'border-zinc-800 bg-zinc-800/50' : 'border-zinc-700 bg-zinc-800' }} p-2 transition hover:border-zinc-500"
                                    wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                >
                                    <div class="flex items-start gap-2">
                                        @unless ($isPast)
                                            <div wire:sort:handle class="mt-0.5 shrink-0 cursor-grab text-zinc-600 hover:text-zinc-400">
                                                <flux:icon name="grip-vertical" class="size-3" />
                                            </div>
                                        @endunless

                                        <div class="min-w-0 flex-1" wire:sort:ignore>
                                            <span class="line-clamp-2 text-xs font-medium {{ $isCompleted ? 'text-zinc-500 line-through' : 'text-zinc-200' }}">
                                                {{ $task->title }}
                                            </span>

                                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                                @if ($task->project)
                                                    <div class="flex items-center gap-0.5 border-l-2 pl-1" style="border-color: {{ $task->project->color }}">
                                                        <span class="text-[10px]">{{ $task->project->emoji }}</span>
                                                    </div>
                                                @endif

                                                @if ($task->priority)
                                                    <div class="size-2 rounded-full bg-{{ $task->priority->color() }}-500"></div>
                                                @endif

                                                @if ($task->estimated_minutes)
                                                    <span class="text-[10px] text-zinc-500">{{ $task->estimated_minutes }}m</span>
                                                @endif

                                                @if ($isCompleted)
                                                    <flux:icon name="check-circle" class="size-3 text-emerald-500" />
                                                @endif
                                            </div>
                                        </div>

                                        @unless ($isPast)
                                            <button
                                                wire:click.stop="removeFromPlan({{ $task->id }}, '{{ $date->toDateString() }}')"
                                                class="shrink-0 text-zinc-600 opacity-0 transition hover:text-red-400 group-hover:opacity-100"
                                            >
                                                <flux:icon name="x-mark" class="size-3" />
                                            </button>
                                        @endunless
                                    </div>
                                </div>
                            </li>
                        @empty
                            <li class="py-4 text-center text-xs text-zinc-600">
                                {{ $isPast ? 'Sem tasks' : 'Arraste tasks aqui' }}
                            </li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endforeach
        </div>
    </div>

    {{-- Mobile Tasks Pool Modal --}}
    <flux:modal name="mobile-tasks-pool" class="w-full max-w-lg space-y-4">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Tasks Disponíveis</flux:heading>
            <flux:badge color="zinc">{{ $this->availableTasks->count() }}</flux:badge>
        </div>

        <div class="max-h-[60vh] space-y-2 overflow-y-auto">
            @forelse ($this->availableTasks as $task)
                <div class="flex items-center justify-between rounded-lg border border-zinc-700 bg-zinc-800 p-3">
                    <div class="min-w-0 flex-1">
                        <span class="line-clamp-2 text-sm font-medium text-zinc-200">{{ $task->title }}</span>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            @if ($task->project)
                                <div class="flex items-center gap-1 border-l-2 pl-1" style="border-color: {{ $task->project->color }}">
                                    <span class="text-xs">{{ $task->project->emoji }}</span>
                                    <span class="text-xs text-zinc-400">{{ Str::limit($task->project->name, 15) }}</span>
                                </div>
                            @endif
                            @if ($task->priority)
                                <flux:badge size="sm" color="{{ $task->priority->color() }}">{{ $task->priority->label() }}</flux:badge>
                            @endif
                            @if ($task->estimated_minutes)
                                <flux:badge size="sm" color="zinc">{{ $task->estimated_minutes }}m</flux:badge>
                            @endif
                        </div>
                    </div>

                    {{-- Add to day dropdown --}}
                    <flux:dropdown>
                        <flux:button size="sm" variant="ghost" icon="plus" />
                        <flux:menu>
                            @foreach ($this->days as $day)
                                @if (!$day['isPast'])
                                    <flux:menu.item
                                        wire:click="addToPlan({{ $task->id }}, '{{ $day['date']->toDateString() }}')"
                                        icon="{{ $day['isToday'] ? 'star' : 'calendar' }}"
                                    >
                                        {{ $day['date']->translatedFormat('D d') }}
                                        @if ($day['isToday'])
                                            <flux:badge size="sm" color="emerald" class="ml-1">Hoje</flux:badge>
                                        @endif
                                    </flux:menu.item>
                                @endif
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @empty
                <div class="py-8 text-center text-sm text-zinc-500">
                    Todas as tasks estão planejadas 🎉
                </div>
            @endforelse
        </div>
    </flux:modal>
</div>
