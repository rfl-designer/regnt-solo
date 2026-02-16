<?php

use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Flux\DateRange;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public DateRange $range;

    #[Url]
    public string $period = 'thisWeek';

    #[Url]
    public string $project = '';

    #[Url]
    public bool $focusOnly = false;

    public function mount(): void
    {
        $this->range = $this->resolveRangeFromPeriod($this->period);
    }

    /**
     * Resolve a DateRange from a period preset string.
     */
    private function resolveRangeFromPeriod(string $period): DateRange
    {
        return match ($period) {
            'today' => DateRange::today(),
            'thisMonth' => DateRange::thisMonth(),
            default => DateRange::thisWeek(),
        };
    }

    public function updatedRange(): void
    {
        $preset = $this->range->preset();

        $this->period = $preset ? $preset->value : 'custom';

        unset($this->entries, $this->entriesByDate, $this->totalMinutes);
    }

    public function updatedPeriod(): void
    {
        if ($this->period !== 'custom') {
            $this->range = $this->resolveRangeFromPeriod($this->period);
        }

        unset($this->entries, $this->entriesByDate, $this->totalMinutes);
    }

    public function updatedProject(): void
    {
        unset($this->entries, $this->entriesByDate, $this->totalMinutes);
    }

    public function updatedFocusOnly(): void
    {
        unset($this->entries, $this->entriesByDate, $this->totalMinutes);
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
     * @return \Illuminate\Database\Eloquent\Collection<int, TimeEntry>
     */
    #[Computed]
    public function entries(): \Illuminate\Database\Eloquent\Collection
    {
        return TimeEntry::query()
            ->whereNotNull('stopped_at')
            ->whereBetween('started_at', [
                $this->range->start()->startOfDay(),
                $this->range->end()->endOfDay(),
            ])
            ->when($this->project !== '', function ($query): void {
                $query->whereRelation('task', 'project_id', (int) $this->project);
            })
            ->when($this->focusOnly, function ($query): void {
                $query->where('is_focus_session', true);
            })
            ->with('task.project')
            ->orderBy('started_at', 'desc')
            ->get();
    }

    /**
     * @return Collection<string, \Illuminate\Database\Eloquent\Collection<int, TimeEntry>>
     */
    #[Computed]
    public function entriesByDate(): Collection
    {
        return $this->entries->groupBy(
            fn (TimeEntry $entry): string => $entry->started_at->toDateString()
        );
    }

    #[Computed]
    public function totalMinutes(): float
    {
        return $this->entries->sum('duration_minutes');
    }

    /**
     * Get total minutes for a specific date group.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, TimeEntry>  $entries
     */
    public function getDayTotal(\Illuminate\Database\Eloquent\Collection $entries): float
    {
        return $entries->sum('duration_minutes');
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

    /**
     * Generate markdown table from filtered entries.
     */
    public function generateMarkdown(): string
    {
        $lines = [];
        $lines[] = '| Data | Task | Projeto | Duração | Focus | Notas |';
        $lines[] = '|------|------|---------|---------|-------|-------|';

        foreach ($this->entriesByDate as $date => $entries) {
            $dateFormatted = Carbon::parse($date)->format('d/m/Y');

            foreach ($entries as $entry) {
                $task = $entry->task?->title ?? '-';
                $project = $entry->task?->project?->name ?? '-';
                $duration = $this->formatDuration($entry->duration_minutes);
                $focus = $entry->is_focus_session ? '🎯' : '';
                $notes = $entry->notes ?? '-';
                $notes = str_replace('|', '\\|', $notes);

                $lines[] = "| {$dateFormatted} | {$task} | {$project} | {$duration} | {$focus} | {$notes} |";
            }

            $dayTotal = $this->formatDuration($this->getDayTotal($entries));
            $lines[] = "| | | **Subtotal** | **{$dayTotal}** | | |";
        }

        $total = $this->formatDuration($this->totalMinutes);
        $lines[] = "| | | **TOTAL** | **{$total}** | | |";

        return implode("\n", $lines);
    }

    public function copyMarkdown(): void
    {
        $this->dispatch('copy-to-clipboard', markdown: $this->generateMarkdown());

        Flux::toast(variant: 'success', heading: 'Copiado!', text: 'Tabela markdown copiada para a área de transferência.');
    }

    #[On('timer-stopped')]
    public function refreshEntries(): void
    {
        unset($this->entries, $this->entriesByDate, $this->totalMinutes);
    }
}

?>

<div
    class="flex h-full w-full flex-1 flex-col gap-4 p-6"
    x-data
    @copy-to-clipboard.window="
        navigator.clipboard.writeText($event.detail.markdown).catch(() => {
            $flux.toast({ variant: 'danger', heading: 'Erro', text: 'Não foi possível copiar.' })
        })
    "
>
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading size="xl">Relatório de Tempo</flux:heading>

        <flux:button
            wire:click="copyMarkdown"
            wire:loading.attr="disabled"
            wire:target="copyMarkdown"
            variant="ghost"
            size="sm"
            icon="clipboard-document"
        >
            Copiar como Markdown
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="w-full sm:w-auto">
            <flux:date-picker
                wire:model.live="range"
                mode="range"
                with-presets
                presets="today thisWeek thisMonth lastWeek lastMonth custom"
                locale="pt-BR"
            />
        </div>

        <div class="w-full sm:w-48">
            <flux:select wire:model.live="project">
                <flux:select.option value="">Todos os projetos</flux:select.option>
                @foreach ($this->projects as $proj)
                    <flux:select.option :value="$proj->id">
                        {{ $proj->emoji }} {{ $proj->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:checkbox
            wire:model.live="focusOnly"
            label="Apenas Focus"
            class="whitespace-nowrap"
        />
    </div>

    {{-- Summary --}}
    <div class="flex items-center gap-4 rounded-xl border border-zinc-700 bg-zinc-900/50 px-4 py-3">
        <div class="flex items-center gap-2">
            <flux:icon name="clock" class="size-5 text-zinc-400" />
            <flux:text class="text-sm text-zinc-400">Total:</flux:text>
            <flux:heading size="sm">{{ $this->formatDuration($this->totalMinutes) }}</flux:heading>
        </div>

        <flux:separator vertical class="h-6" />

        <div class="flex items-center gap-2">
            <flux:icon name="document-text" class="size-5 text-zinc-400" />
            <flux:text class="text-sm text-zinc-400">Registros:</flux:text>
            <flux:heading size="sm">{{ $this->entries->count() }}</flux:heading>
        </div>
    </div>

    {{-- Table --}}
    @if ($this->entries->isEmpty())
        <div class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <flux:icon name="clock" class="mx-auto mb-3 size-12 text-zinc-500" />
                <flux:heading size="lg">Nenhum registro encontrado</flux:heading>
                <flux:text class="mt-1">Ajuste os filtros ou registre tempo em suas tasks.</flux:text>
            </div>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Data</flux:table.column>
                <flux:table.column>Task</flux:table.column>
                <flux:table.column>Projeto</flux:table.column>
                <flux:table.column>Duração</flux:table.column>
                <flux:table.column>Focus</flux:table.column>
                <flux:table.column>Notas</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->entriesByDate as $date => $dayEntries)
                    @php($dateCarbon = Carbon::parse($date))

                    @foreach ($dayEntries as $entry)
                        <flux:table.row :key="$entry->id">
                            {{-- Show date only on first entry of the group --}}
                            <flux:table.cell variant="strong">
                                @if ($loop->first)
                                    {{ $dateCarbon->format('d/m/Y') }}
                                    <flux:text class="text-xs text-zinc-500">
                                        {{ $dateCarbon->translatedFormat('l') }}
                                    </flux:text>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $entry->task?->title ?? '-' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($entry->task?->project)
                                    <div class="flex items-center gap-1 border-l-2 pl-1.5" style="border-color: {{ $entry->task->project->color }}">
                                        <span class="text-xs">{{ $entry->task->project->emoji }}</span>
                                        <span class="truncate text-xs text-zinc-400">{{ $entry->task->project->name }}</span>
                                    </div>
                                @else
                                    <flux:text class="text-xs text-zinc-500">-</flux:text>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc" icon="clock">
                                    {{ $this->formatDuration($entry->duration_minutes) }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($entry->is_focus_session)
                                    <flux:badge size="sm" color="amber">
                                        🎯 Focus
                                        @if ($entry->focus_rating)
                                            <span class="ml-1">{{ $entry->focus_rating }}⭐</span>
                                        @endif
                                    </flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:text class="max-w-xs truncate text-xs text-zinc-400">
                                    {{ $entry->notes ?? '-' }}
                                </flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach

                    {{-- Day subtotal row --}}
                    <flux:table.row wire:key="subtotal-{{ $date }}">
                        <flux:table.cell></flux:table.cell>
                        <flux:table.cell></flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="text-xs font-semibold text-zinc-300">Subtotal</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="blue" icon="clock">
                                {{ $this->formatDuration($this->getDayTotal($dayEntries)) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell></flux:table.cell>
                        <flux:table.cell></flux:table.cell>
                    </flux:table.row>
                @endforeach

                {{-- Grand total row --}}
                <flux:table.row wire:key="grand-total">
                    <flux:table.cell></flux:table.cell>
                    <flux:table.cell></flux:table.cell>
                    <flux:table.cell>
                        <flux:text class="text-sm font-bold text-zinc-200">TOTAL</flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="emerald" icon="clock">
                            {{ $this->formatDuration($this->totalMinutes) }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell></flux:table.cell>
                    <flux:table.cell></flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    @endif
</div>
