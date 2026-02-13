<?php

use App\Models\TimeEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * Get the currently running time entry with its task.
     */
    #[Computed]
    public function activeEntry(): ?TimeEntry
    {
        return TimeEntry::with('task')->running()->first();
    }

    /**
     * Stop the running timer and open the notes modal.
     */
    public function stop(): void
    {
        $entry = $this->activeEntry;

        if (! $entry) {
            return;
        }

        $this->dispatch('open-timer-notes', entryId: $entry->id);

        unset($this->activeEntry);

        $this->dispatch('timer-updated');
    }

    /**
     * Refresh computed state when timer is updated elsewhere.
     */
    #[On('timer-updated')]
    public function refreshState(): void
    {
        unset($this->activeEntry);
    }
}

?>

<div
    x-data="{
        startedAt: @js($this->activeEntry?->started_at?->toIso8601String()),
        elapsed: '00:00:00',
        interval: null,

        init() {
            if (this.startedAt) {
                this.tick();
                this.startInterval();
            }

            $wire.on('timer-updated', () => {
                this.$nextTick(() => {
                    this.startedAt = $wire.$get('activeEntry')?.started_at;

                    if (this.startedAt) {
                        this.tick();
                        if (!this.interval) {
                            this.startInterval();
                        }
                    } else {
                        this.stopInterval();
                    }
                });
            });
        },

        startInterval() {
            this.interval = setInterval(() => this.tick(), 1000);
        },

        tick() {
            if (!this.startedAt) return;
            const diff = Math.floor((Date.now() - new Date(this.startedAt).getTime()) / 1000);
            const h = String(Math.floor(diff / 3600)).padStart(2, '0');
            const m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
            const s = String(diff % 60).padStart(2, '0');
            this.elapsed = `${h}:${m}:${s}`;
        },

        stopInterval() {
            clearInterval(this.interval);
            this.interval = null;
            this.elapsed = '00:00:00';
        },
    }"
    x-effect="
        if (startedAt && !interval) {
            tick();
            startInterval();
        } else if (!startedAt && interval) {
            stopInterval();
        }
    "
>
    @if ($this->activeEntry)
        <div class="inline-flex items-center gap-2">
            <span class="text-sm">⏱</span>

            <button
                type="button"
                class="text-sm font-medium text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100 truncate max-w-32"
                wire:click="$dispatch('open-task-modal', { taskId: {{ $this->activeEntry->task_id }} })"
            >
                {{ $this->activeEntry->task->title }}
            </button>

            <span class="text-sm font-mono text-zinc-500 dark:text-zinc-400" x-text="elapsed">00:00:00</span>

            <flux:button
                wire:click="stop"
                size="xs"
                variant="danger"
                icon="stop"
                square
                tooltip="Parar timer"
            />
        </div>
    @endif
</div>
