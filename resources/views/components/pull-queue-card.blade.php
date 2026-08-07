@props(['entry' => null, 'activity' => null, 'agingBorder' => null, 'agingTooltip' => null])

@php
    $task = $entry?->activity ?? $activity;

    // Two kinds of card share this markup: one that earned a position in
    // the queue (and explains it), and one that is out of the queue because
    // its Épico's Spec is still with the client (issue #146).
    $reason = $entry?->positionReason()
        ?? 'fora da fila: a spec do épico ainda está em aprovação';

    // The card already explains its position; when it is also burning
    // through the SLE (issue #145), that goes in the same tooltip rather
    // than a second one stacked on top of it.
    $tooltip = $agingTooltip ? $reason.' · '.$agingTooltip : $reason;
@endphp

{{-- A card in the Pronto column (issue #144).

     Same card as everywhere else on the board, minus the drag handle: the
     column's order is derived from the pull queue, so there is nothing to
     grab and reorder. Dragging the card itself still moves it into another
     column. The tooltip carries the motivo of the position, so "why is
     this one on top" is answerable without leaving the board. --}}
<li wire:key="task-{{ $task->id }}" wire:sort:item="{{ $task->id }}" class="kanban-card">
    <flux:tooltip :content="$tooltip">
        <div
            class="group cursor-pointer rounded-lg border {{ $agingBorder ?? 'border-zinc-700' }} bg-zinc-800 p-3 transition-all duration-200 hover:border-zinc-500 hover:shadow-lg hover:shadow-zinc-900/50"
            wire:click="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
        >
            {{-- Card Top: Title --}}
            <div class="flex items-start gap-2">
                <span class="line-clamp-2 flex-1 text-sm font-medium text-zinc-200">{{ $task->title }}</span>
                @if ($task->effective_client)
                    <div class="mt-1 size-2.5 shrink-0 rounded-full" style="background-color: {{ $task->effective_client->color }}" title="{{ $task->effective_client->name }}"></div>
                @endif
            </div>

            {{-- Project Info --}}
            @if ($task->project)
                <div class="mt-2 flex items-center gap-1.5 border-l-2 pl-2" style="border-color: {{ $task->project->color }}">
                    <span class="text-xs">{{ $task->project->emoji }}</span>
                    <span class="truncate text-xs text-zinc-400">{{ $task->project->name }}</span>
                </div>
            @endif

            {{-- Spec ainda em aprovação (issue #146): sinal discreto, não um
                 bloqueio — o card continua arrastável e clicável. --}}
            @if ($entry === null)
                <div class="mt-2 flex items-center gap-1 text-xs text-violet-400/80" data-test="spec-pending-signal">
                    <flux:icon name="paper-airplane" class="size-3.5" />
                    <span>spec em aprovação</span>
                </div>
            @endif

            {{-- Badges Row --}}
            <div class="mt-2 flex flex-wrap items-center gap-1.5" wire:sort:ignore>
                <flux:badge size="sm" color="{{ $task->type->color() }}" icon="{{ $task->type->icon() }}">
                    {{ $task->derivedLabel() }}
                </flux:badge>

                {{-- Service Class Badge --}}
                @if ($task->service_class)
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

                @if ($task->isRunning())
                    <flux:badge size="sm" color="emerald" class="animate-pulse">
                        <div class="mr-1 size-2 rounded-full bg-emerald-400"></div>
                        Timer
                    </flux:badge>
                @endif

                @if ($task->commits_count > 0)
                    <flux:badge size="sm" color="zinc" icon="code-bracket">
                        {{ $task->commits_count }} {{ $task->commits_count === 1 ? 'commit' : 'commits' }}
                    </flux:badge>
                @endif

                @if ($task->isSessionTask())
                    <flux:badge size="sm" color="violet" class="gap-1">
                        🤖 Sessão
                    </flux:badge>
                @endif
            </div>
        </div>
    </flux:tooltip>
</li>
