<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * Get total deep work minutes for today.
     */
    #[Computed]
    public function deepWorkToday(): float
    {
        return TimeEntry::focusDurationMinutes(Carbon::today());
    }

    /**
     * Calculate average time in each status for tasks completed in the last 30 days.
     *
     * @return array<string, array{label: string, avg_minutes: float, formatted: string, color: string, hex_color: string}>
     */
    #[Computed]
    public function averageTimeByStatus(): array
    {
        $doneTasks = Task::query()
            ->where('status', TaskStatus::Done)
            ->where('completed_at', '>=', Carbon::now()->subDays(30))
            ->with('statusChanges')
            ->get();

        if ($doneTasks->isEmpty()) {
            return [];
        }

        $totals = [];
        $counts = [];

        foreach (TaskStatus::cases() as $status) {
            $totals[$status->value] = 0.0;
            $counts[$status->value] = 0;
        }

        foreach ($doneTasks as $task) {
            $timeInStatus = $task->time_in_status;

            foreach ($timeInStatus as $statusValue => $minutes) {
                if ($minutes > 0) {
                    $totals[$statusValue] += $minutes;
                    $counts[$statusValue]++;
                }
            }
        }

        $averages = [];

        foreach (TaskStatus::cases() as $status) {
            $count = $counts[$status->value];

            if ($count === 0) {
                continue;
            }

            $avgMinutes = $totals[$status->value] / $count;

            $averages[$status->value] = [
                'label' => $status->label(),
                'avg_minutes' => round($avgMinutes, 1),
                'formatted' => $this->formatDuration($avgMinutes),
                'color' => $status->color(),
                'hex_color' => $status->hexColor(),
            ];
        }

        return $averages;
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
            return "{$days}d {$hours}h {$mins}m";
        }

        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }

        return "{$mins}m";
    }

    #[On('task-updated')]
    public function refreshMetrics(): void
    {
        unset($this->averageTimeByStatus, $this->deepWorkToday);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <flux:heading size="xl">Dashboard</flux:heading>

    <div class="grid auto-rows-min gap-4 md:grid-cols-3">
        <div class="relative aspect-video overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
            <div class="flex h-full items-center justify-center text-zinc-500">
                <flux:icon name="chart-bar-square" class="size-8" />
            </div>
        </div>
        <div class="relative aspect-video overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
            <div class="flex h-full items-center justify-center text-zinc-500">
                <flux:icon name="inbox" class="size-8" />
            </div>
        </div>
        <div class="relative overflow-hidden rounded-xl border border-amber-500/20 bg-zinc-800/50 p-5">
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-lg bg-amber-500/10">
                    <span class="text-lg">🎯</span>
                </div>
                <div>
                    <flux:text class="text-xs text-zinc-400">Deep Work hoje</flux:text>
                    <flux:heading size="lg">{{ $this->formatDuration($this->deepWorkToday) }}</flux:heading>
                </div>
            </div>
        </div>
    </div>

    {{-- Average Time by Status Section --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
        <div class="mb-4 flex items-center gap-2">
            <flux:icon name="chart-bar" class="size-5 text-zinc-400" />
            <flux:heading size="sm">Tempo médio por status</flux:heading>
            <flux:text class="text-xs text-zinc-500">(últimos 30 dias)</flux:text>
        </div>

        @if (count($this->averageTimeByStatus) > 0)
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-5">
                @foreach ($this->averageTimeByStatus as $statusValue => $data)
                    <div class="rounded-lg border border-zinc-700 bg-zinc-900/50 p-3">
                        <div class="mb-1 flex items-center gap-1.5">
                            <div class="size-2.5 rounded-full" style="background-color: {{ $data['hex_color'] }};"></div>
                            <flux:text class="text-xs text-zinc-400">{{ $data['label'] }}</flux:text>
                        </div>
                        <flux:heading size="sm">{{ $data['formatted'] }}</flux:heading>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex items-center justify-center py-6 text-center">
                <div>
                    <flux:icon name="clock" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm text-zinc-500">Sem dados suficientes</flux:text>
                    <flux:text class="text-xs text-zinc-600">Conclua tasks para ver métricas de tempo.</flux:text>
                </div>
            </div>
        @endif
    </div>

    <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
        <div class="flex h-full min-h-48 items-center justify-center text-zinc-500">
            <div class="text-center">
                <flux:icon name="squares-2x2" class="mx-auto mb-3 size-12" />
                <flux:heading size="lg">Bem-vindo ao SoloBoard</flux:heading>
                <flux:text class="mt-1">Seu painel de gestão pessoal de projetos.</flux:text>
            </div>
        </div>
    </div>
</div>
