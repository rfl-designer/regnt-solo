<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TimeEntry;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Task $task;

    public bool $fullscreen = false;

    public function mount(Task $task): void
    {
        $this->task = $task;

        // Auto-start timer if not running
        if (! $this->task->isRunning()) {
            $this->startTimer();
        }

        // Auto-set status to doing if not already
        if ($this->task->status !== TaskStatus::Doing) {
            $this->task->update(['status' => TaskStatus::Doing]);
        }
    }

    #[Computed]
    public function sessionPrompt(): string
    {
        return $this->task->session_prompt ?? '';
    }

    #[Computed]
    public function isSessionTask(): bool
    {
        return $this->task->isSessionTask();
    }

    #[Computed]
    public function runningEntry(): ?TimeEntry
    {
        return $this->task->timeEntries()->running()->first();
    }

    #[Computed]
    public function elapsedMinutes(): int
    {
        $entry = $this->runningEntry;

        if (! $entry) {
            return 0;
        }

        return (int) $entry->started_at->diffInMinutes(now());
    }

    public function startTimer(): void
    {
        // Stop any running timer first
        TimeEntry::query()
            ->running()
            ->each(fn (TimeEntry $entry) => $entry->stop());

        // Start new timer
        $this->task->timeEntries()->create([
            'started_at' => now(),
            'is_focus' => true,
        ]);

        Flux::toast('Timer iniciado');
    }

    public function stopTimer(): void
    {
        $entry = $this->runningEntry;

        if ($entry) {
            $entry->stop();
            Flux::toast('Timer pausado');
        }
    }

    public function toggleFullscreen(): void
    {
        $this->fullscreen = ! $this->fullscreen;
    }

    #[On('close-terminal')]
    public function closeTerminal(): void
    {
        $this->redirect(route('kanban'), navigate: true);
    }

    #[On('toggle-fullscreen')]
    public function onToggleFullscreen(): void
    {
        $this->toggleFullscreen();
    }

    public function markAsDone(): void
    {
        // Stop timer if running
        $this->stopTimer();

        // Update task status
        $this->task->update([
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);

        Flux::toast('Task concluída!');
        $this->redirect(route('kanban'), navigate: true);
    }
}

?>

<div class="flex flex-col h-[calc(100vh-4rem)]">
    {{-- Header --}}
    <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-700 bg-zinc-900/50">
        <div class="flex items-center gap-4">
            <flux:button
                variant="ghost"
                icon="arrow-left"
                href="{{ route('kanban') }}"
                wire:navigate
            />

            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-lg font-semibold text-zinc-100">
                        {{ $task->title }}
                    </h1>
                    @if($this->isSessionTask)
                        <flux:badge color="violet" size="sm">
                            Session
                        </flux:badge>
                    @endif
                </div>
                <div class="flex items-center gap-3 mt-1 text-sm text-zinc-400">
                    @if($task->project)
                        <span class="flex items-center gap-1">
                            <span
                                class="w-2 h-2 rounded-full"
                                style="background-color: {{ $task->project->color }}"
                            ></span>
                            {{ $task->project->name }}
                        </span>
                    @endif
                    <span>Task #{{ $task->id }}</span>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            {{-- Timer --}}
            <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-zinc-800 border border-zinc-700">
                @if($this->runningEntry)
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-sm font-mono text-zinc-300" wire:poll.10s>
                        {{ floor($this->elapsedMinutes / 60) }}h {{ $this->elapsedMinutes % 60 }}m
                    </span>
                    <flux:button
                        variant="ghost"
                        size="xs"
                        icon="pause"
                        wire:click="stopTimer"
                    />
                @else
                    <span class="w-2 h-2 rounded-full bg-zinc-500"></span>
                    <span class="text-sm text-zinc-500">Pausado</span>
                    <flux:button
                        variant="ghost"
                        size="xs"
                        icon="play"
                        wire:click="startTimer"
                    />
                @endif
            </div>

            {{-- Actions --}}
            <flux:button
                variant="ghost"
                icon="{{ $fullscreen ? 'arrows-pointing-in' : 'arrows-pointing-out' }}"
                wire:click="toggleFullscreen"
            />

            <flux:button
                variant="primary"
                icon="check"
                wire:click="markAsDone"
            >
                Concluir
            </flux:button>
        </div>
    </div>

    {{-- Terminal --}}
    <div class="flex-1 min-h-0 p-4">
        <livewire:terminal-session
            :task-id="$task->id"
            :session-prompt="$this->sessionPrompt"
            :fullscreen="$fullscreen"
        />
    </div>
</div>
