<?php

use App\Models\TimeEntry;
use App\Models\WeeklyReview;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $week = '';

    public string $reflection = '';

    public function mount(): void
    {
        if ($this->week === '' || ! $this->isValidDate($this->week)) {
            $this->week = now()->startOfWeek()->toDateString();
        }

        $this->reflection = $this->review->reflection ?? '';
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
    public function review(): WeeklyReview
    {
        return WeeklyReview::getOrCreateForWeek(Carbon::parse($this->week));
    }

    #[Computed]
    public function weekStart(): Carbon
    {
        return Carbon::parse($this->week)->startOfWeek();
    }

    #[Computed]
    public function weekEnd(): Carbon
    {
        return Carbon::parse($this->week)->endOfWeek();
    }

    #[Computed]
    public function isCurrentWeek(): bool
    {
        return $this->weekStart->isSameWeek(now());
    }

    #[Computed]
    public function completedTasks(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->review->completedTasks();
    }

    #[Computed]
    public function totalHours(): float
    {
        return $this->review->totalHours();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{project: \App\Models\Project|null, minutes: float}>
     */
    #[Computed]
    public function hoursByProject(): \Illuminate\Support\Collection
    {
        return $this->review->hoursByProject();
    }

    #[Computed]
    public function staleTasks(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->review->staleTasks();
    }

    /**
     * @return array{created: int, completed: int}
     */
    #[Computed]
    public function tasksCreatedVsCompleted(): array
    {
        return $this->review->tasksCreatedVsCompleted();
    }

    /**
     * Get total focus hours for this week.
     */
    #[Computed]
    public function focusHours(): float
    {
        $range = $this->review->weekRange();

        return TimeEntry::query()
            ->focusSessions()
            ->whereNotNull('stopped_at')
            ->whereBetween('started_at', $range)
            ->get()
            ->sum('duration_minutes') / 60;
    }

    /**
     * Get the focus ratio (% of tracked time that was deep work).
     */
    #[Computed]
    public function focusRatio(): float
    {
        $totalHours = $this->totalHours;

        if ($totalHours <= 0) {
            return 0;
        }

        return round(($this->focusHours / $totalHours) * 100, 1);
    }

    /**
     * Get the average focus rating for this week.
     */
    #[Computed]
    public function averageFocusRating(): ?float
    {
        $range = $this->review->weekRange();

        $avg = TimeEntry::query()
            ->focusSessions()
            ->whereNotNull('stopped_at')
            ->whereNotNull('focus_rating')
            ->whereBetween('started_at', $range)
            ->avg('focus_rating');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * Get the focus streak (consecutive days with 2+ hours of deep work in last 28 days).
     */
    #[Computed]
    public function focusStreak(): int
    {
        $startDate = Carbon::today()->subDays(27);

        $dailyMinutes = TimeEntry::query()
            ->focusSessions()
            ->whereNotNull('stopped_at')
            ->whereDate('started_at', '>=', $startDate)
            ->get()
            ->groupBy(fn (TimeEntry $entry): string => $entry->started_at->toDateString())
            ->map(fn ($entries): float => $entries->sum('duration_minutes'));

        $streak = 0;
        $date = Carbon::today();

        for ($i = 0; $i < 28; $i++) {
            $minutes = $dailyMinutes->get($date->toDateString(), 0.0);

            if ($minutes >= 120) {
                $streak++;
            } else {
                break;
            }

            $date = $date->subDay();
        }

        return $streak;
    }

    /**
     * Get previous reviews with pre-calculated summary data.
     *
     * @return \Illuminate\Support\Collection<int, array{review: WeeklyReview, completed_count: int, total_hours: float}>
     */
    #[Computed]
    public function previousReviews(): \Illuminate\Support\Collection
    {
        return WeeklyReview::query()
            ->where('week_start', '<', $this->weekStart->toDateString())
            ->orderByDesc('week_start')
            ->limit(4)
            ->get()
            ->map(fn (WeeklyReview $review): array => [
                'review' => $review,
                'completed_count' => $review->completedTasks()->count(),
                'total_hours' => $review->totalHours(),
            ]);
    }

    public function previousWeek(): void
    {
        $this->week = $this->weekStart->subWeek()->toDateString();

        $this->invalidateComputed();
    }

    public function nextWeek(): void
    {
        if ($this->isCurrentWeek) {
            return;
        }

        $this->week = $this->weekStart->addWeek()->toDateString();

        $this->invalidateComputed();
    }

    public function goToWeek(string $date): void
    {
        $this->week = Carbon::parse($date)->startOfWeek()->toDateString();

        $this->invalidateComputed();
    }

    public function updatedReflection(): void
    {
        $this->review->update(['reflection' => $this->reflection]);
    }

    /**
     * Format minutes into a human-readable duration string.
     */
    public function formatDuration(float $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = (int) floor($minutes / 60);
        $mins = (int) round($minutes % 60);

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}m";
        }

        if ($hours > 0) {
            return "{$hours}h";
        }

        return "{$mins}m";
    }

    private function invalidateComputed(): void
    {
        unset(
            $this->review,
            $this->weekStart,
            $this->weekEnd,
            $this->isCurrentWeek,
            $this->completedTasks,
            $this->totalHours,
            $this->hoursByProject,
            $this->staleTasks,
            $this->tasksCreatedVsCompleted,
            $this->previousReviews,
            $this->focusHours,
            $this->focusRatio,
            $this->averageFocusRating,
            $this->focusStreak,
        );

        $this->reflection = $this->review->reflection ?? '';
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    {{-- Breadcrumb --}}
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" wire:navigate icon="home">
            Dashboard
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>
            Review
        </flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
            <flux:heading size="xl">Weekly Review</flux:heading>

            @if ($this->isCurrentWeek)
                <flux:badge color="emerald" icon="check-circle">Semana atual</flux:badge>
            @endif
        </div>

        {{-- Week Navigation --}}
        <div class="flex items-center gap-2">
            <flux:button wire:click="previousWeek" variant="ghost" size="sm" icon="chevron-left">
                Anterior
            </flux:button>

            <div class="rounded-lg border border-zinc-700 bg-zinc-900/50 px-4 py-2">
                <flux:text class="text-sm font-medium text-zinc-200">
                    Semana de {{ $this->weekStart->format('d/m') }} - {{ $this->weekEnd->format('d/m') }}
                </flux:text>
            </div>

            <flux:button
                wire:click="nextWeek"
                variant="ghost"
                size="sm"
                icon-trailing="chevron-right"
                :disabled="$this->isCurrentWeek"
            >
                Proxima
            </flux:button>
        </div>
    </div>

    {{-- Resumo Cards --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        {{-- Tasks Completadas --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4">
            <div class="mb-1 flex items-center gap-2">
                <flux:icon name="check-circle" class="size-5 text-emerald-400" />
                <flux:text class="text-xs text-zinc-400">Completadas</flux:text>
            </div>
            <flux:heading size="xl">{{ $this->completedTasks->count() }}</flux:heading>
        </div>

        {{-- Horas Totais --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4">
            <div class="mb-1 flex items-center gap-2">
                <flux:icon name="clock" class="size-5 text-blue-400" />
                <flux:text class="text-xs text-zinc-400">Horas totais</flux:text>
            </div>
            <flux:heading size="xl">{{ $this->formatDuration($this->totalHours * 60) }}</flux:heading>
        </div>

        {{-- Projetos Trabalhados --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4">
            <div class="mb-1 flex items-center gap-2">
                <flux:icon name="folder" class="size-5 text-purple-400" />
                <flux:text class="text-xs text-zinc-400">Projetos</flux:text>
            </div>
            <flux:heading size="xl">{{ $this->hoursByProject->count() }}</flux:heading>
        </div>

        {{-- Tasks Criadas vs Completadas --}}
        @php
            $ratio = $this->tasksCreatedVsCompleted;
            $ratioColor = $ratio['completed'] >= $ratio['created'] ? 'text-emerald-400' : 'text-red-400';
        @endphp
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4">
            <div class="mb-1 flex items-center gap-2">
                <flux:icon name="arrow-trending-up" class="size-5 {{ $ratioColor }}" />
                <flux:text class="text-xs text-zinc-400">Criadas / Completadas</flux:text>
            </div>
            <flux:heading size="xl">
                <span>{{ $ratio['created'] }}</span>
                <span class="text-zinc-500">/</span>
                <span class="{{ $ratioColor }}">{{ $ratio['completed'] }}</span>
            </flux:heading>
        </div>
    </div>

    {{-- Trabalho Focado --}}
    <div class="rounded-xl border border-amber-500/20 bg-zinc-800/50 p-5">
        <div class="mb-4 flex items-center gap-2">
            <span class="text-lg">🎯</span>
            <flux:heading size="sm">Trabalho Focado</flux:heading>
        </div>

        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            {{-- Horas de Foco --}}
            <div class="rounded-lg border border-zinc-700 bg-zinc-900/50 p-3">
                <flux:text class="mb-1 text-xs text-zinc-400">Horas de foco</flux:text>
                <flux:heading size="lg">{{ $this->formatDuration($this->focusHours * 60) }}</flux:heading>
            </div>

            {{-- Taxa de Foco --}}
            <div class="rounded-lg border border-zinc-700 bg-zinc-900/50 p-3">
                <flux:text class="mb-1 text-xs text-zinc-400">Taxa de foco</flux:text>
                <flux:heading size="lg">{{ $this->focusRatio }}%</flux:heading>
            </div>

            {{-- Avaliação Média --}}
            <div class="rounded-lg border border-zinc-700 bg-zinc-900/50 p-3">
                <flux:text class="mb-1 text-xs text-zinc-400">Avaliação média</flux:text>
                <flux:heading size="lg">
                    @if ($this->averageFocusRating !== null)
                        {{ $this->averageFocusRating }} ⭐
                    @else
                        -
                    @endif
                </flux:heading>
            </div>

            {{-- Sequência --}}
            <div class="rounded-lg border border-zinc-700 bg-zinc-900/50 p-3">
                <flux:text class="mb-1 text-xs text-zinc-400">Sequência (+2h/dia)</flux:text>
                <flux:heading size="lg">
                    @if ($this->focusStreak > 0)
                        🔥 {{ $this->focusStreak }} {{ $this->focusStreak === 1 ? 'dia' : 'dias' }}
                    @else
                        0 dias
                    @endif
                </flux:heading>
            </div>
        </div>
    </div>

    {{-- Main Content Grid --}}
    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Horas por Projeto --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
            <div class="mb-4 flex items-center gap-2">
                <flux:icon name="chart-bar" class="size-5 text-zinc-400" />
                <flux:heading size="sm">Horas por Projeto</flux:heading>
            </div>

            @if ($this->hoursByProject->isNotEmpty())
                @php
                    $maxMinutes = $this->hoursByProject->max('minutes');
                @endphp

                <div class="space-y-3">
                    @foreach ($this->hoursByProject as $entry)
                        @php
                            $project = $entry['project'];
                            $percentage = $maxMinutes > 0 ? ($entry['minutes'] / $maxMinutes) * 100 : 0;
                        @endphp

                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    @if ($project)
                                        <span class="text-sm">{{ $project->emoji }}</span>
                                        <flux:text class="text-sm text-zinc-200">{{ $project->name }}</flux:text>
                                    @else
                                        <flux:text class="text-sm text-zinc-400">Sem Projeto</flux:text>
                                    @endif
                                </div>
                                <flux:text class="text-sm font-medium text-zinc-300">
                                    {{ $this->formatDuration($entry['minutes']) }}
                                </flux:text>
                            </div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-zinc-700">
                                <div
                                    class="h-full rounded-full transition-all duration-500"
                                    style="width: {{ $percentage }}%; background-color: {{ $project?->color ?? '#71717a' }}"
                                ></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="flex items-center justify-center py-8 text-center">
                    <div>
                        <flux:icon name="clock" class="mx-auto mb-2 size-8 text-zinc-600" />
                        <flux:text class="text-sm text-zinc-500">Nenhum registro de tempo nesta semana</flux:text>
                    </div>
                </div>
            @endif
        </div>

        {{-- Tasks Completadas --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
            <div class="mb-4 flex items-center gap-2">
                <flux:icon name="clipboard-document-check" class="size-5 text-zinc-400" />
                <flux:heading size="sm">Tasks Completadas</flux:heading>
                @if ($this->completedTasks->isNotEmpty())
                    <flux:badge size="sm" color="zinc">{{ $this->completedTasks->count() }}</flux:badge>
                @endif
            </div>

            @if ($this->completedTasks->isNotEmpty())
                <div class="divide-y divide-zinc-700/50">
                    @foreach ($this->completedTasks as $task)
                        <div wire:key="completed-{{ $task->id }}" class="flex items-center gap-3 py-3">
                            <flux:icon name="check-circle" class="size-4 shrink-0 text-emerald-400" />

                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="truncate text-sm font-medium text-zinc-200">{{ $task->title }}</span>

                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($task->project)
                                        <div class="flex items-center gap-1 border-l-2 pl-1.5" style="border-color: {{ $task->project->color }}">
                                            <span class="text-xs">{{ $task->project->emoji }}</span>
                                            <span class="truncate text-xs text-zinc-400">{{ $task->project->name }}</span>
                                        </div>
                                    @endif

                                    @if ($task->priority)
                                        <flux:badge size="sm" color="{{ $task->priority->color() }}" icon="{{ $task->priority->icon() }}">
                                            {{ $task->priority->label() }}
                                        </flux:badge>
                                    @endif

                                    @php
                                        $taskMinutes = $task->timeEntries
                                            ->whereNotNull('stopped_at')
                                            ->sum('duration_minutes');
                                    @endphp
                                    @if ($taskMinutes > 0)
                                        <flux:badge size="sm" color="zinc" icon="clock">
                                            {{ $this->formatDuration($taskMinutes) }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="flex items-center justify-center py-8 text-center">
                    <div>
                        <flux:icon name="clipboard-document-list" class="mx-auto mb-2 size-8 text-zinc-600" />
                        <flux:text class="text-sm text-zinc-500">Nenhuma task completada nesta semana</flux:text>
                    </div>
                </div>
            @endif
        </div>

        {{-- Atencao Necessaria --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
            <div class="mb-4 flex items-center gap-2">
                <flux:icon name="exclamation-triangle" class="size-5 text-amber-400" />
                <flux:heading size="sm">Atencao Necessaria</flux:heading>
                @if ($this->staleTasks->isNotEmpty())
                    <flux:badge size="sm" color="amber">{{ $this->staleTasks->count() }}</flux:badge>
                @endif
            </div>

            @if ($this->staleTasks->isNotEmpty())
                <div class="divide-y divide-zinc-700/50">
                    @foreach ($this->staleTasks as $task)
                        <div wire:key="stale-{{ $task->id }}" class="flex items-center gap-3 py-3">
                            <flux:icon name="exclamation-circle" class="size-4 shrink-0 text-amber-400" />

                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="truncate text-sm font-medium text-zinc-200">{{ $task->title }}</span>

                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($task->project)
                                        <div class="flex items-center gap-1 border-l-2 pl-1.5" style="border-color: {{ $task->project->color }}">
                                            <span class="text-xs">{{ $task->project->emoji }}</span>
                                            <span class="truncate text-xs text-zinc-400">{{ $task->project->name }}</span>
                                        </div>
                                    @endif

                                    <flux:badge size="sm" color="{{ $task->status->color() }}" icon="{{ $task->status->icon() }}">
                                        {{ $task->status->label() }}
                                    </flux:badge>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="flex items-center justify-center py-8 text-center">
                    <div>
                        <flux:icon name="check-circle" class="mx-auto mb-2 size-8 text-emerald-500" />
                        <flux:text class="text-sm text-zinc-400">Todas as tasks tiveram progresso!</flux:text>
                    </div>
                </div>
            @endif
        </div>

        {{-- Reflexao --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
            <div class="mb-4 flex items-center gap-2">
                <flux:icon name="pencil-square" class="size-5 text-zinc-400" />
                <flux:heading size="sm">Reflexao</flux:heading>
            </div>

            <flux:textarea
                wire:model.blur="reflection"
                placeholder="O que funcionou bem esta semana? O que pode melhorar?"
                rows="5"
            />

            @if ($this->review->reflection)
                <flux:text class="mt-2 text-xs text-zinc-500">
                    Salvo automaticamente
                </flux:text>
            @endif
        </div>
    </div>

    {{-- Historico --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
        <div class="mb-4 flex items-center gap-2">
            <flux:icon name="clock" class="size-5 text-zinc-400" />
            <flux:heading size="sm">Historico</flux:heading>
        </div>

        @if ($this->previousReviews->isNotEmpty())
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->previousReviews as $entry)
                    @php($prevReview = $entry['review'])
                    <button
                        wire:key="history-{{ $prevReview->id }}"
                        wire:click="goToWeek('{{ $prevReview->week_start->toDateString() }}')"
                        class="rounded-lg border border-zinc-700 bg-zinc-900/50 p-3 text-left transition-colors hover:border-zinc-600 hover:bg-zinc-800/50"
                    >
                        <flux:text class="text-sm font-medium text-zinc-200">
                            {{ $prevReview->week_start->format('d/m') }} - {{ $prevReview->week_end->format('d/m') }}
                        </flux:text>
                        <div class="mt-1 flex items-center gap-3">
                            <flux:text class="text-xs text-zinc-400">
                                {{ $entry['completed_count'] }} tasks
                            </flux:text>
                            <flux:text class="text-xs text-zinc-400">
                                {{ $this->formatDuration($entry['total_hours'] * 60) }}
                            </flux:text>
                        </div>
                    </button>
                @endforeach
            </div>
        @else
            <div class="flex items-center justify-center py-6 text-center">
                <div>
                    <flux:icon name="calendar" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Nenhum review anterior encontrado</flux:text>
                </div>
            </div>
        @endif
    </div>
</div>
