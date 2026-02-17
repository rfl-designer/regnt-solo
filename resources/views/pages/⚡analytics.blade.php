<?php

use App\Models\Project;
use App\Services\AnalyticsService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $period = '12w';

    public ?int $cycleTimeProjectId = null;

    #[Computed]
    public function heatmap(): array
    {
        return app(AnalyticsService::class)->contributionHeatmap($this->periodToWeeks());
    }

    #[Computed]
    public function streakData(): array
    {
        return app(AnalyticsService::class)->streaks();
    }

    #[Computed]
    public function velocity(): array
    {
        return app(AnalyticsService::class)->velocityTrend($this->periodToWeeks());
    }

    #[Computed]
    public function cycleTimeData(): array
    {
        return app(AnalyticsService::class)->cycleTime($this->periodToString(), $this->cycleTimeProjectId);
    }

    #[Computed]
    public function healthScores(): array
    {
        return app(AnalyticsService::class)->projectHealthScores();
    }

    #[Computed]
    public function patterns(): array
    {
        return app(AnalyticsService::class)->productivityPatterns($this->periodToWeeks());
    }

    #[Computed]
    public function focusRatioValue(): float
    {
        return app(AnalyticsService::class)->focusRatio($this->periodToString());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::active()->orderBy('name')->get();
    }

    public function updatedPeriod(): void
    {
        unset(
            $this->heatmap,
            $this->streakData,
            $this->velocity,
            $this->cycleTimeData,
            $this->healthScores,
            $this->patterns,
            $this->focusRatioValue,
        );
    }

    public function updatedCycleTimeProjectId(): void
    {
        unset($this->cycleTimeData);
    }

    /**
     * Convert the period selector value to weeks.
     */
    private function periodToWeeks(): int
    {
        return match ($this->period) {
            '4w' => 4,
            '12w' => 12,
            '6m' => 26,
            default => 12,
        };
    }

    /**
     * Convert the period selector value to a service-compatible period string.
     */
    private function periodToString(): string
    {
        return match ($this->period) {
            '4w' => '4w',
            '12w' => '12w',
            '6m' => '6m',
            default => '12w',
        };
    }

    /**
     * Format minutes into a human-readable duration string.
     */
    public function formatDuration(float $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $totalMinutes = (int) round($minutes);
        $days = intdiv($totalMinutes, 1440);
        $hours = intdiv($totalMinutes % 1440, 60);
        $mins = $totalMinutes % 60;

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }

        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }

        return "{$mins}m";
    }

    /**
     * Translate English day names to Portuguese.
     */
    public function translateDay(string $day): string
    {
        return match ($day) {
            'Monday' => 'Segunda',
            'Tuesday' => 'Terca',
            'Wednesday' => 'Quarta',
            'Thursday' => 'Quinta',
            'Friday' => 'Sexta',
            'Saturday' => 'Sabado',
            'Sunday' => 'Domingo',
            default => $day,
        };
    }

    /**
     * Get abbreviated Portuguese day name for the heatmap.
     */
    public function shortDay(int $dayOfWeek): string
    {
        return match ($dayOfWeek) {
            0 => 'Seg',
            1 => 'Ter',
            2 => 'Qua',
            3 => 'Qui',
            4 => 'Sex',
            5 => 'Sab',
            6 => 'Dom',
            default => '',
        };
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
            Analytics
        </flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading size="xl">Analytics Pessoais</flux:heading>

        <flux:select wire:model.live="period" size="sm" class="min-w-44">
            <flux:select.option value="4w">4 semanas</flux:select.option>
            <flux:select.option value="12w">12 semanas</flux:select.option>
            <flux:select.option value="6m">6 meses</flux:select.option>
        </flux:select>
    </div>

    {{-- Section 1: Contribution Heatmap --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
        <div class="mb-4 flex items-center gap-2">
            <flux:icon name="calendar-days" class="size-5 text-emerald-400" />
            <flux:heading size="sm">Atividade</flux:heading>
            <flux:text class="text-xs text-zinc-500">
                ({{ $this->periodToWeeks() }} semanas)
            </flux:text>
        </div>

        @if (count($this->heatmap) > 0)
            @php
                $weeks = collect($this->heatmap)->groupBy(function ($day) {
                    return \Carbon\Carbon::parse($day['date'])->startOfWeek()->toDateString();
                });
                $levelColors = [
                    0 => 'bg-zinc-800',
                    1 => 'bg-emerald-900',
                    2 => 'bg-emerald-700',
                    3 => 'bg-emerald-500',
                    4 => 'bg-emerald-400',
                ];
            @endphp

            <div
                class="relative"
                x-data="{ tooltip: { show: false, text: '', x: 0, y: 0 } }"
            >
                {{-- Tooltip --}}
                <div
                    x-show="tooltip.show"
                    x-transition.opacity.duration.150ms
                    class="pointer-events-none fixed z-50 rounded-lg border border-zinc-600 bg-zinc-900 px-3 py-2 text-xs text-zinc-200 shadow-lg"
                    :style="'left: ' + tooltip.x + 'px; top: ' + tooltip.y + 'px; transform: translate(-50%, -120%);'"
                    x-cloak
                >
                    <span x-text="tooltip.text"></span>
                </div>

                {{-- Heatmap Grid --}}
                <div class="overflow-x-auto">
                    <div class="inline-flex gap-1">
                        {{-- Day labels --}}
                        <div class="flex flex-col gap-1 pr-1 pt-0">
                            @for ($d = 0; $d < 7; $d++)
                                <div class="flex h-3 w-6 items-center text-[10px] text-zinc-500">
                                    @if ($d % 2 === 0)
                                        {{ $this->shortDay($d) }}
                                    @endif
                                </div>
                            @endfor
                        </div>

                        {{-- Week columns --}}
                        @foreach ($weeks as $weekStart => $days)
                            <div class="flex flex-col gap-1" wire:key="week-{{ $weekStart }}">
                                @php
                                    $weekStartCarbon = \Carbon\Carbon::parse($weekStart);
                                    $daysByDow = collect($days)->keyBy(fn ($d) => \Carbon\Carbon::parse($d['date'])->dayOfWeekIso - 1);
                                @endphp

                                @for ($d = 0; $d < 7; $d++)
                                    @php
                                        $day = $daysByDow->get($d);
                                        $level = $day ? $day['level'] : 0;
                                        $colorClass = $levelColors[$level];
                                        $dateFormatted = $day ? \Carbon\Carbon::parse($day['date'])->format('d/m') : '';
                                        $hours = $day ? number_format($day['hours'], 1) : '0';
                                        $tasks = $day ? $day['tasks_completed'] : 0;
                                        $tooltipText = $day ? "{$dateFormatted}: {$hours}h tracked, {$tasks} tasks" : '';
                                    @endphp

                                    @if ($day)
                                        <div
                                            class="{{ $colorClass }} size-3 cursor-pointer rounded-sm transition-colors hover:ring-1 hover:ring-zinc-400"
                                            @mouseenter="tooltip = { show: true, text: '{{ $tooltipText }}', x: $event.clientX, y: $event.clientY }"
                                            @mouseleave="tooltip.show = false"
                                        ></div>
                                    @else
                                        <div class="size-3 rounded-sm bg-zinc-800/50"></div>
                                    @endif
                                @endfor
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Legend --}}
                <div class="mt-3 flex items-center justify-end gap-1.5">
                    <flux:text class="text-xs text-zinc-500">Menos</flux:text>
                    @foreach ($levelColors as $colorClass)
                        <div class="{{ $colorClass }} size-3 rounded-sm"></div>
                    @endforeach
                    <flux:text class="text-xs text-zinc-500">Mais ativo</flux:text>
                </div>
            </div>
        @else
            <div class="flex items-center justify-center py-8 text-center">
                <div>
                    <flux:icon name="calendar" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Sem dados de atividade</flux:text>
                </div>
            </div>
        @endif
    </div>

    {{-- Section 2: Sequências --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @php
            $streaks = $this->streakData;
        @endphp

        {{-- Sequência Atual --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4">
            <div class="mb-1 flex items-center gap-2">
                <span class="text-lg">🔥</span>
                <flux:text class="text-xs text-zinc-400">Sequência atual</flux:text>
            </div>
            <flux:heading size="xl">{{ $streaks['current'] }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">
                {{ $streaks['current'] === 1 ? 'dia consecutivo' : 'dias consecutivos' }}
            </flux:text>
        </div>

        {{-- Melhor Sequência --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4">
            <div class="mb-1 flex items-center gap-2">
                <flux:icon name="trophy" class="size-5 text-amber-400" />
                <flux:text class="text-xs text-zinc-400">Melhor sequência</flux:text>
            </div>
            <flux:heading size="xl">{{ $streaks['best'] }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">dias (recorde)</flux:text>
        </div>

        {{-- Sequência de Foco Atual --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4">
            <div class="mb-1 flex items-center gap-2">
                <span class="text-lg">🎯</span>
                <flux:text class="text-xs text-zinc-400">Sequência de foco</flux:text>
            </div>
            <flux:heading size="xl">{{ $streaks['focus_current'] }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">dias com +2h de foco</flux:text>
        </div>

        {{-- Melhor Sequência de Foco --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4">
            <div class="mb-1 flex items-center gap-2">
                <flux:icon name="star" class="size-5 text-purple-400" />
                <flux:text class="text-xs text-zinc-400">Melhor foco</flux:text>
            </div>
            <flux:heading size="xl">{{ $streaks['focus_best'] }}</flux:heading>
            <flux:text class="text-xs text-zinc-500">dias (recorde foco)</flux:text>
        </div>
    </div>

    {{-- Section 3: Tendência de Velocidade --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
        <div class="mb-4 flex items-center gap-2">
            <flux:icon name="arrow-trending-up" class="size-5 text-blue-400" />
            <flux:heading size="sm">Velocidade</flux:heading>
            <flux:text class="text-xs text-zinc-500">(tasks criadas vs completadas por semana)</flux:text>
        </div>

        @if (count($this->velocity) > 0)
            @php
                $velocityData = $this->velocity;
                $maxCount = max(
                    collect($velocityData)->max('completed_count'),
                    collect($velocityData)->max('created_count'),
                    1
                );
            @endphp

            {{-- Legend --}}
            <div class="mb-3 flex items-center gap-4">
                <div class="flex items-center gap-1.5">
                    <div class="size-3 rounded-sm bg-emerald-500"></div>
                    <flux:text class="text-xs text-zinc-400">Completadas</flux:text>
                </div>
                <div class="flex items-center gap-1.5">
                    <div class="size-3 rounded-sm bg-blue-500"></div>
                    <flux:text class="text-xs text-zinc-400">Criadas</flux:text>
                </div>
            </div>

            <div class="overflow-x-auto">
                <div class="flex items-end gap-1" style="min-height: 120px;">
                    @foreach ($velocityData as $week)
                        @php
                            $completedHeight = $maxCount > 0 ? ($week['completed_count'] / $maxCount) * 100 : 0;
                            $createdHeight = $maxCount > 0 ? ($week['created_count'] / $maxCount) * 100 : 0;
                            $weekLabel = \Carbon\Carbon::parse($week['week_start'])->format('d/m');
                        @endphp

                        <div
                            wire:key="velocity-{{ $week['week_start'] }}"
                            class="flex flex-1 flex-col items-center gap-0.5"
                            style="min-width: 32px;"
                        >
                            <div class="flex w-full items-end justify-center gap-0.5" style="height: 100px;">
                                <flux:tooltip content="Completadas: {{ $week['completed_count'] }}" position="top">
                                    <div
                                        class="w-3 rounded-t-sm bg-emerald-500 transition-all duration-300"
                                        style="height: {{ max($completedHeight, 2) }}%;"
                                    ></div>
                                </flux:tooltip>
                                <flux:tooltip content="Criadas: {{ $week['created_count'] }}" position="top">
                                    <div
                                        class="w-3 rounded-t-sm bg-blue-500 transition-all duration-300"
                                        style="height: {{ max($createdHeight, 2) }}%;"
                                    ></div>
                                </flux:tooltip>
                            </div>
                            <flux:text class="text-[10px] text-zinc-500">{{ $weekLabel }}</flux:text>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="flex items-center justify-center py-8 text-center">
                <div>
                    <flux:icon name="arrow-trending-up" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Sem dados de velocity</flux:text>
                </div>
            </div>
        @endif
    </div>

    {{-- Section 4: Cycle Time --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <flux:icon name="clock" class="size-5 text-amber-400" />
                <flux:heading size="sm">Cycle Time</flux:heading>
                <flux:text class="text-xs text-zinc-500">(tempo medio de conclusao)</flux:text>
            </div>

            <flux:select wire:model.live="cycleTimeProjectId" size="sm" class="min-w-40">
                <flux:select.option :value="null">Todos os projetos</flux:select.option>
                @foreach ($this->projects as $proj)
                    <flux:select.option :value="$proj->id">
                        {{ $proj->emoji }} {{ $proj->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if (count($this->cycleTimeData) > 0)
            @php
                $cycleData = $this->cycleTimeData;
                $maxMinutes = collect($cycleData)->max('avg_minutes') ?: 1;
            @endphp

            <div class="overflow-x-auto">
                <div class="flex items-end gap-0.5" style="min-height: 120px;">
                    @foreach ($cycleData as $entry)
                        @php
                            $barHeight = ($entry['avg_minutes'] / $maxMinutes) * 100;
                            $dateLabel = \Carbon\Carbon::parse($entry['date'])->format('d/m');
                            $durationLabel = $this->formatDuration($entry['avg_minutes']);
                        @endphp

                        <div
                            wire:key="cycle-{{ $entry['date'] }}"
                            class="flex flex-1 flex-col items-center gap-0.5"
                            style="min-width: 16px;"
                        >
                            <div class="flex w-full items-end justify-center" style="height: 100px;">
                                <flux:tooltip content="{{ $dateLabel }}: {{ $durationLabel }}" position="top">
                                    <div
                                        class="w-full max-w-4 rounded-t-sm bg-amber-500/80 transition-all duration-300 hover:bg-amber-400"
                                        style="height: {{ max($barHeight, 3) }}%;"
                                    ></div>
                                </flux:tooltip>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Summary --}}
            <div class="mt-3 flex items-center gap-4 border-t border-zinc-700 pt-3">
                <div class="flex items-center gap-2">
                    <flux:text class="text-xs text-zinc-400">Media geral:</flux:text>
                    <flux:badge size="sm" color="amber">
                        {{ $this->formatDuration(collect($cycleData)->avg('avg_minutes')) }}
                    </flux:badge>
                </div>
                <div class="flex items-center gap-2">
                    <flux:text class="text-xs text-zinc-400">Registros:</flux:text>
                    <flux:badge size="sm" color="zinc">{{ count($cycleData) }}</flux:badge>
                </div>
            </div>
        @else
            <div class="flex items-center justify-center py-8 text-center">
                <div>
                    <flux:icon name="clock" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Sem dados de cycle time</flux:text>
                    <flux:text class="text-xs text-zinc-600">Conclua tasks para ver metricas.</flux:text>
                </div>
            </div>
        @endif
    </div>

    {{-- Section 5: Project Health Scores --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
        <div class="mb-4 flex items-center gap-2">
            <flux:icon name="heart" class="size-5 text-red-400" />
            <flux:heading size="sm">Saude dos Projetos</flux:heading>
        </div>

        @if (count($this->healthScores) > 0)
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->healthScores as $project)
                    @php
                        $score = $project['score'];
                        $scoreColor = match (true) {
                            $score > 70 => 'text-emerald-400',
                            $score > 40 => 'text-amber-400',
                            default => 'text-red-400',
                        };
                        $borderColor = match (true) {
                            $score > 70 => 'border-emerald-500/20',
                            $score > 40 => 'border-amber-500/20',
                            default => 'border-red-500/20',
                        };
                        $bgColor = match (true) {
                            $score > 70 => 'bg-emerald-500',
                            $score > 40 => 'bg-amber-500',
                            default => 'bg-red-500',
                        };
                        $factors = $project['factors'];
                    @endphp

                    <div
                        wire:key="health-{{ $project['project_id'] }}"
                        class="rounded-lg border {{ $borderColor }} bg-zinc-900/50 p-4"
                        x-data="{ expanded: false }"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex min-w-0 flex-1 items-center gap-2">
                                <flux:text class="truncate text-sm font-medium text-zinc-200">
                                    {{ $project['project'] }}
                                </flux:text>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="lg" class="{{ $scoreColor }}">
                                    {{ $score }}
                                </flux:heading>
                                <button
                                    @click="expanded = !expanded"
                                    class="rounded p-0.5 text-zinc-500 transition-colors hover:text-zinc-300"
                                >
                                    <flux:icon name="chevron-down" class="size-4 transition-transform" ::class="expanded && 'rotate-180'" />
                                </button>
                            </div>
                        </div>

                        {{-- Score bar --}}
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-700">
                            <div
                                class="{{ $bgColor }} h-full rounded-full transition-all duration-500"
                                style="width: {{ $score }}%;"
                            ></div>
                        </div>

                        {{-- Expandable factors --}}
                        <div x-show="expanded" x-collapse x-cloak class="mt-3 space-y-1.5 border-t border-zinc-700 pt-3">
                            <div class="flex items-center justify-between">
                                <flux:text class="text-xs text-zinc-400">Tasks paradas</flux:text>
                                <flux:text class="text-xs text-zinc-300">{{ $factors['stale_tasks'] }}/{{ $factors['total_tasks'] }}</flux:text>
                            </div>
                            <div class="flex items-center justify-between">
                                <flux:text class="text-xs text-zinc-400">Tasks atrasadas</flux:text>
                                <flux:text class="text-xs text-zinc-300">{{ $factors['overdue_tasks'] }}/{{ $factors['total_tasks'] }}</flux:text>
                            </div>
                            <div class="flex items-center justify-between">
                                <flux:text class="text-xs text-zinc-400">Dias sem atividade</flux:text>
                                <flux:text class="text-xs text-zinc-300">{{ $factors['days_since_last_activity'] }}</flux:text>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex items-center justify-center py-8 text-center">
                <div>
                    <flux:icon name="folder" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Nenhum projeto ativo encontrado</flux:text>
                </div>
            </div>
        @endif
    </div>

    {{-- Section 6: Productivity Patterns --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
        <div class="mb-4 flex items-center gap-2">
            <flux:icon name="light-bulb" class="size-5 text-yellow-400" />
            <flux:heading size="sm">Padroes de Produtividade</flux:heading>
        </div>

        @php
            $patternsData = $this->patterns;
        @endphp

        @if (count($patternsData['best_days']) > 0 || count($patternsData['best_hours']) > 0)
            {{-- Insight text --}}
            <div class="mb-4 rounded-lg border border-zinc-700 bg-zinc-900/50 p-4">
                <flux:text class="text-sm text-zinc-200">
                    @if (count($patternsData['best_days']) > 0)
                        Voce e mais produtivo
                        <span class="font-semibold text-emerald-400">
                            {{ implode(' e ', array_map(fn ($d) => $this->translateDay($d), $patternsData['best_days'])) }}
                        </span>
                    @endif
                    @if (count($patternsData['best_hours']) > 0)
                        @if (count($patternsData['best_days']) > 0), @endif
                        entre
                        <span class="font-semibold text-blue-400">
                            {{ min($patternsData['best_hours']) }}h-{{ max($patternsData['best_hours']) + 1 }}h
                        </span>
                    @endif
                </flux:text>
            </div>

            {{-- Productivity Heatmap: Days × Hours --}}
            @php
                $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                $shortDayNames = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'];
                $startHour = 6;
                $endHour = 22;
                $avgByDay = $patternsData['avg_by_day'];
                $avgByHour = $patternsData['avg_by_hour'];

                // Build a combined intensity matrix
                $maxDayAvg = max(array_values($avgByDay)) ?: 1;
                $maxHourAvg = max(array_values($avgByHour)) ?: 1;
            @endphp

            <div class="overflow-x-auto">
                <div class="inline-block">
                    {{-- Hour labels --}}
                    <div class="mb-1 flex">
                        <div class="w-10 shrink-0"></div>
                        @for ($h = $startHour; $h <= $endHour; $h++)
                            <div class="w-6 text-center text-[10px] text-zinc-500">{{ $h }}</div>
                        @endfor
                    </div>

                    {{-- Day rows --}}
                    @foreach ($dayNames as $idx => $dayName)
                        <div class="flex items-center gap-0">
                            <div class="w-10 shrink-0 text-right text-[10px] text-zinc-500 pr-1.5">
                                {{ $shortDayNames[$idx] }}
                            </div>
                            @for ($h = $startHour; $h <= $endHour; $h++)
                                @php
                                    $dayIntensity = $maxDayAvg > 0 ? ($avgByDay[$dayName] ?? 0) / $maxDayAvg : 0;
                                    $hourIntensity = $maxHourAvg > 0 ? ($avgByHour[$h] ?? 0) / $maxHourAvg : 0;
                                    $combined = ($dayIntensity + $hourIntensity) / 2;

                                    $cellColor = match (true) {
                                        $combined > 0.7 => 'bg-emerald-400',
                                        $combined > 0.5 => 'bg-emerald-500',
                                        $combined > 0.3 => 'bg-emerald-700',
                                        $combined > 0.1 => 'bg-emerald-900',
                                        default => 'bg-zinc-800',
                                    };

                                    $hourVal = number_format($avgByHour[$h] ?? 0, 1);
                                @endphp

                                <flux:tooltip content="{{ $this->translateDay($dayName) }} {{ $h }}h: {{ $hourVal }}h avg" position="top">
                                    <div class="{{ $cellColor }} m-px size-5 rounded-sm transition-colors"></div>
                                </flux:tooltip>
                            @endfor
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Legend --}}
            <div class="mt-3 flex items-center justify-end gap-1.5">
                <flux:text class="text-xs text-zinc-500">Menos</flux:text>
                <div class="size-3 rounded-sm bg-zinc-800"></div>
                <div class="size-3 rounded-sm bg-emerald-900"></div>
                <div class="size-3 rounded-sm bg-emerald-700"></div>
                <div class="size-3 rounded-sm bg-emerald-500"></div>
                <div class="size-3 rounded-sm bg-emerald-400"></div>
                <flux:text class="text-xs text-zinc-500">Mais ativo</flux:text>
            </div>
        @else
            <div class="flex items-center justify-center py-8 text-center">
                <div>
                    <flux:icon name="light-bulb" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Sem dados suficientes para detectar padroes</flux:text>
                    <flux:text class="text-xs text-zinc-600">Registre tempo para ver seus padroes de produtividade.</flux:text>
                </div>
            </div>
        @endif
    </div>

    {{-- Taxa de Foco --}}
    <div class="rounded-xl border border-amber-500/20 bg-zinc-800/50 p-5">
        <div class="flex items-center gap-3">
            <div class="flex size-10 items-center justify-center rounded-lg bg-amber-500/10">
                <span class="text-lg">🎯</span>
            </div>
            <div>
                <flux:text class="text-xs text-zinc-400">Taxa de foco (período selecionado)</flux:text>
                <flux:heading size="lg">{{ number_format($this->focusRatioValue * 100, 1) }}%</flux:heading>
            </div>
            <div class="ml-auto">
                <flux:text class="text-xs text-zinc-500">do tempo total em trabalho focado</flux:text>
            </div>
        </div>

        {{-- Progress bar --}}
        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-zinc-700">
            <div
                class="h-full rounded-full bg-amber-500 transition-all duration-500"
                style="width: {{ min($this->focusRatioValue * 100, 100) }}%;"
            ></div>
        </div>
    </div>
</div>
