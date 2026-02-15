<?php

use App\Models\TimeEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $taskId;

    /**
     * Check if there is a running time entry for this task.
     */
    #[Computed]
    public function isRunning(): bool
    {
        return TimeEntry::query()
            ->where('task_id', $this->taskId)
            ->running()
            ->exists();
    }

    /**
     * Get the running time entry for this task.
     */
    #[Computed]
    public function runningEntry(): ?TimeEntry
    {
        return TimeEntry::query()
            ->where('task_id', $this->taskId)
            ->running()
            ->first();
    }

    /**
     * Check if the running entry is a focus session.
     */
    #[Computed]
    public function isFocusSession(): bool
    {
        return $this->runningEntry?->is_focus_session ?? false;
    }

    /**
     * Toggle the timer: start if stopped, stop if running.
     */
    public function toggle(): void
    {
        if ($this->isRunning) {
            $this->stop();
        } else {
            $this->start();
        }
    }

    /**
     * Start a new time entry for this task, stopping any other running timers.
     */
    public function start(): void
    {
        TimeEntry::stopAllRunning();

        TimeEntry::create([
            'task_id' => $this->taskId,
            'started_at' => now(),
            'is_focus_session' => false,
        ]);

        unset($this->isRunning, $this->runningEntry, $this->isFocusSession);

        $this->dispatch('timer-updated');
    }

    /**
     * Start a focus session for this task, stopping any other running timers.
     */
    public function startFocus(): void
    {
        TimeEntry::stopAllRunning();

        TimeEntry::create([
            'task_id' => $this->taskId,
            'started_at' => now(),
            'is_focus_session' => true,
        ]);

        unset($this->isRunning, $this->runningEntry, $this->isFocusSession);

        $this->dispatch('timer-updated');
    }

    /**
     * Stop the running time entry and open the notes modal.
     */
    public function stop(): void
    {
        $entry = $this->runningEntry;

        if (! $entry) {
            return;
        }

        $this->dispatch('open-timer-notes', entryId: $entry->id);

        unset($this->isRunning, $this->runningEntry, $this->isFocusSession);

        $this->dispatch('timer-updated');
    }

    /**
     * Refresh computed state when timer is updated elsewhere.
     */
    #[On('timer-updated')]
    public function refreshState(): void
    {
        unset($this->isRunning, $this->runningEntry, $this->isFocusSession);
    }
}

?>

<div
    x-data="{
        running: $wire.$get('isRunning'),
        isFocus: $wire.$get('isFocusSession'),
        startedAt: @js($this->runningEntry?->started_at?->toIso8601String()),
        elapsed: '00:00:00',
        interval: null,

        init() {
            if (this.running && this.startedAt) {
                this.tick();
                this.startInterval();
            }

            $wire.on('timer-updated', () => {
                this.$nextTick(() => {
                    this.running = $wire.$get('isRunning');
                    this.isFocus = $wire.$get('isFocusSession');
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
        if (running && startedAt && !interval) {
            tick();
            startInterval();
        } else if (!running && interval) {
            stopInterval();
        }
    "
>
    <div class="flex items-center gap-1">
        @if ($this->isRunning)
            {{-- Running timer button (stop) --}}
            <flux:button
                wire:click="stop"
                size="xs"
                :variant="$this->isFocusSession ? 'filled' : 'danger'"
                icon="stop"
                :class="$this->isFocusSession ? 'bg-amber-600! hover:bg-amber-500! text-white!' : ''"
            >
                @if ($this->isFocusSession)
                    <span class="mr-0.5">🎯</span>
                @endif
                <span x-text="elapsed">00:00:00</span>
            </flux:button>
        @else
            {{-- Start normal timer --}}
            <flux:button
                wire:click="toggle"
                size="xs"
                variant="ghost"
                icon="play"
                square
                tooltip="Iniciar timer"
            />

            {{-- Start focus session --}}
            <flux:button
                wire:click="startFocus"
                size="xs"
                variant="ghost"
                icon="eye"
                square
                tooltip="Iniciar modo foco"
                class="text-amber-400! hover:text-amber-300!"
            />
        @endif
    </div>
</div>
