@props([
    'feature',
    'expanded' => false,
    'showProject' => true,
    'sortable' => false,
])

@php
    $tasksCount = $feature->tasksCount();
    $completedCount = $feature->completedTasksCount();
    $totalTime = $feature->total_time;
    $statusColor = $feature->status->color();
@endphp

<div
    wire:key="feature-{{ $feature->id }}"
    class="group rounded-lg border border-zinc-700 bg-zinc-800 transition-all duration-200 hover:border-zinc-500 hover:shadow-lg hover:shadow-zinc-900/50"
>
    {{-- Card Header --}}
    <div class="flex items-start gap-2 p-3">
        @if ($sortable)
            <div
                wire:sort:handle
                x-on:click.stop
                class="mt-0.5 shrink-0 cursor-grab text-zinc-600 transition-colors hover:text-zinc-300 active:cursor-grabbing"
            >
                <flux:icon name="grip-vertical" class="size-4" />
            </div>
        @endif

        <div
            class="min-w-0 flex-1 cursor-pointer"
            wire:click="$dispatch('open-feature-modal', { featureId: {{ $feature->id }} })"
        >
        {{-- Title + ID --}}
        <div class="flex items-start justify-between gap-2">
            <h3 class="line-clamp-2 text-sm font-medium text-zinc-200">
                {{ $feature->title }}
            </h3>
            <span
                x-data="{ copied: false }"
                x-on:click.stop="navigator.clipboard.writeText('#F-{{ $feature->id }}'); copied = true; setTimeout(() => copied = false, 1500)"
                class="shrink-0 cursor-pointer text-xs font-mono transition-colors duration-200"
                :class="copied ? 'text-emerald-400' : 'text-zinc-500 hover:text-zinc-300'"
                title="Copiar ID"
            >
                <span x-show="!copied">#F-{{ $feature->id }}</span>
                <span x-show="copied" x-cloak>Copiado!</span>
            </span>
        </div>

        {{-- Project Info --}}
        @if ($showProject && $feature->project)
            <div class="mt-2 flex items-center gap-1.5 border-l-2 pl-2" style="border-color: {{ $feature->project->color }}">
                <span class="text-xs">{{ $feature->project->emoji }}</span>
                <span class="truncate text-xs text-zinc-400">{{ $feature->project->name }}</span>
            </div>
        @endif

        {{-- Progress Bar --}}
        @if ($tasksCount > 0)
            <div class="mt-3">
                <div class="mb-1 flex items-center justify-between text-xs">
                    <span class="text-zinc-400">Progresso</span>
                    <span class="font-medium text-zinc-300">{{ $completedCount }}/{{ $tasksCount }} tasks</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-700">
                    <div
                        class="h-full rounded-full bg-{{ $statusColor }}-500 transition-all duration-300"
                        style="width: {{ $feature->progress }}%"
                    ></div>
                </div>
            </div>
        @endif

        {{-- Badges Row --}}
        <div class="mt-3 flex flex-wrap items-center gap-1.5">
            {{-- Priority Badge --}}
            @if ($feature->priority)
                <flux:badge size="sm" color="{{ $feature->priority->color() }}" icon="{{ $feature->priority->icon() }}">
                    {{ $feature->priority->label() }}
                </flux:badge>
            @endif

            {{-- Time Badge --}}
            @if ($totalTime > 0)
                @php
                    $hours = intdiv((int) $totalTime, 60);
                    $mins = (int) $totalTime % 60;
                    $formattedTime = $hours > 0 && $mins > 0 ? "{$hours}h {$mins}m" : ($hours > 0 ? "{$hours}h" : "{$mins}m");
                @endphp
                <flux:badge size="sm" color="zinc" icon="clock">
                    {{ $formattedTime }}
                </flux:badge>
            @endif

            {{-- Due Date --}}
            @if ($feature->due_date)
                @php
                    $isOverdue = $feature->due_date->isPast();
                @endphp
                <flux:badge size="sm" color="{{ $isOverdue ? 'red' : 'zinc' }}" icon="{{ $isOverdue ? 'exclamation-triangle' : 'calendar' }}">
                    {{ $feature->due_date->format('d/m') }}
                </flux:badge>
            @endif

            {{-- Timer Control --}}
            <div class="ml-auto">
                @if ($feature->isRunning())
                    <button
                        type="button"
                        wire:click.stop="stopTimer({{ $feature->id }})"
                        class="flex items-center gap-1 rounded-md bg-violet-600/20 px-2 py-1 text-xs font-medium text-violet-400 transition hover:bg-violet-600/30"
                        title="Parar timer"
                    >
                        <div class="size-2 animate-pulse rounded-full bg-violet-400"></div>
                        <span>Timer</span>
                        <flux:icon name="stop" class="size-3" />
                    </button>
                @else
                    <button
                        type="button"
                        wire:click.stop="startTimer({{ $feature->id }})"
                        class="flex items-center gap-1 rounded-md bg-zinc-700/50 px-2 py-1 text-xs font-medium text-zinc-400 opacity-0 transition group-hover:opacity-100 hover:bg-zinc-600 hover:text-zinc-200"
                        title="Iniciar timer"
                    >
                        <flux:icon name="play" class="size-3" />
                    </button>
                @endif
            </div>
        </div>
        </div>
    </div>

    {{-- Expand/Collapse Tasks --}}
    @if ($tasksCount > 0)
        <div class="border-t border-zinc-700">
            <button
                type="button"
                wire:click.stop="toggleExpanded({{ $feature->id }})"
                aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                aria-controls="tasks-list-{{ $feature->id }}"
                class="flex w-full items-center justify-between px-3 py-2 text-xs text-zinc-400 transition hover:bg-zinc-700/50 hover:text-zinc-300"
            >
                <span>{{ $tasksCount }} {{ $tasksCount === 1 ? 'task' : 'tasks' }}</span>
                <flux:icon
                    :name="$expanded ? 'chevron-up' : 'chevron-down'"
                    class="size-4"
                />
            </button>

            {{-- Tasks List (Expandable) --}}
            @if ($expanded)
                <div id="tasks-list-{{ $feature->id }}" class="border-t border-zinc-700/50 bg-zinc-800/50 px-3 py-2">
                    <ul class="space-y-1.5">
                        @foreach ($feature->children->sortBy('sort_order') as $task)
                            <li
                                wire:click.stop="$dispatch('open-task-modal', { taskId: {{ $task->id }} })"
                                class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-xs transition hover:bg-zinc-700"
                            >
                                {{-- Status indicator --}}
                                <div class="size-2 shrink-0 rounded-full bg-{{ $task->status->color() }}-400"></div>

                                {{-- Title --}}
                                <span class="flex-1 truncate text-zinc-300">{{ $task->title }}</span>

                                {{-- Priority --}}
                                @if ($task->priority)
                                    <flux:icon
                                        :name="$task->priority->icon()"
                                        class="size-3 shrink-0 text-{{ $task->priority->color() }}-400"
                                    />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Drill-down Button --}}
            <div class="border-t border-zinc-700">
                <button
                    type="button"
                    wire:click.stop="enterDrill({{ $feature->id }})"
                    class="flex w-full items-center justify-center gap-1.5 px-3 py-2 text-xs text-zinc-400 transition hover:bg-zinc-700/50 hover:text-zinc-200"
                >
                    <flux:icon name="squares-plus" class="size-3.5" />
                    <span>Ver {{ $tasksCount }} {{ $tasksCount === 1 ? 'task' : 'tasks' }}</span>
                    <flux:icon name="arrow-right" class="size-3" />
                </button>
            </div>
        </div>
    @endif
</div>
