<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Services\AiAssistantService;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sort')]
    public string $sortBy = 'created_at';

    #[Url(as: 'dir')]
    public string $sortDirection = 'desc';

    public bool $showDeleteModal = false;

    public ?int $deletingTaskId = null;

    public ?string $deletingTaskTitle = null;

    /** @var array<int, array{task_id: int, action: string, reason: string, suggested_priority: string|null, suggested_project: string|null}> */
    public array $backlogAnalysis = [];

    public bool $showBacklogAnalysis = false;

    public bool $aiLoading = false;

    /** @var array<int, int> */
    public array $selectedTasks = [];

    public bool $showBulkDeleteModal = false;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    #[Computed]
    public function tasks(): \Illuminate\Database\Eloquent\Collection
    {
        $query = Activity::inbox()->with('project');

        if ($this->search !== '') {
            $query->whereRaw('lower(title) like ?', ['%'.mb_strtolower($this->search).'%']);
        }

        return $query->orderBy($this->sortBy, $this->sortDirection)->get();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        unset($this->tasks);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::active()->orderBy('name')->get();
    }

    public function assignProject(int $taskId, ?string $projectId): void
    {
        $task = Activity::inbox()->findOrFail($taskId);

        $validProjectId = ($projectId !== '' && $projectId !== null)
            ? Project::findOrFail((int) $projectId)->id
            : null;

        $task->update(['project_id' => $validProjectId]);

        unset($this->tasks);

        Flux::toast(variant: 'success', heading: 'Projeto atualizado', text: $task->title);
    }

    public function updatePriority(int $taskId, string $priority): void
    {
        $task = Activity::inbox()->findOrFail($taskId);

        $newPriority = ActivityPriority::from($priority);

        $task->update(['priority' => $newPriority]);

        unset($this->tasks);

        Flux::toast(variant: 'success', heading: 'Prioridade atualizada', text: $task->title);
    }

    /**
     * Get statuses available to move tasks to (all except Inbox).
     *
     * @return array<ActivityStatus>
     */
    public function availableStatuses(): array
    {
        return [
            ActivityStatus::Backlog,
            ActivityStatus::Todo,
            ActivityStatus::Doing,
            ActivityStatus::Done,
        ];
    }

    public function selectAll(): void
    {
        $this->selectedTasks = $this->tasks->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function deselectAll(): void
    {
        $this->selectedTasks = [];
    }

    public function bulkMoveToStatus(string $status): void
    {
        if (empty($this->selectedTasks)) {
            return;
        }

        $newStatus = ActivityStatus::from($status);
        $updateData = ['status' => $newStatus];

        if ($newStatus === ActivityStatus::Done) {
            $updateData['completed_at'] = now();
        }

        Activity::inbox()
            ->whereIn('id', $this->selectedTasks)
            ->get()
            ->each(fn (Activity $task) => $task->update($updateData));

        $count = count($this->selectedTasks);
        $this->selectedTasks = [];

        unset($this->tasks);
        $this->dispatch('task-moved');

        Flux::toast(variant: 'success', heading: "{$count} tasks movidas", text: "Para: {$newStatus->label()}");
    }

    public function confirmBulkDelete(): void
    {
        if (empty($this->selectedTasks)) {
            return;
        }

        $this->showBulkDeleteModal = true;
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedTasks)) {
            return;
        }

        $count = count($this->selectedTasks);

        Activity::inbox()->whereIn('id', $this->selectedTasks)->get()->each(fn (Activity $task) => $task->delete());

        $this->selectedTasks = [];
        $this->showBulkDeleteModal = false;

        unset($this->tasks);
        $this->dispatch('task-moved');

        Flux::toast(text: 'Tasks removidas da caixa de entrada', variant: 'success', heading: "{$count} tasks excluídas");
    }

    public function moveToStatus(int $taskId, string $status): void
    {
        $task = Activity::inbox()->findOrFail($taskId);

        $newStatus = ActivityStatus::from($status);

        $updateData = ['status' => $newStatus];

        if ($newStatus === ActivityStatus::Done) {
            $updateData['completed_at'] = now();
        }

        $task->update($updateData);

        unset($this->tasks);

        $this->dispatch('task-moved');

        Flux::toast(variant: 'success', heading: "Movida para {$newStatus->label()}", text: $task->title);
    }

    public function confirmDelete(int $taskId): void
    {
        $task = Activity::inbox()->findOrFail($taskId);

        $this->deletingTaskId = $task->id;
        $this->deletingTaskTitle = $task->title;
        $this->showDeleteModal = true;
    }

    public function deleteTask(): void
    {
        if ($this->deletingTaskId === null) {
            return;
        }

        $task = Activity::inbox()->findOrFail($this->deletingTaskId);
        $title = $task->title;

        $task->delete();

        $this->reset('deletingTaskId', 'deletingTaskTitle', 'showDeleteModal');

        unset($this->tasks);

        $this->dispatch('task-moved');

        Flux::toast(variant: 'success', heading: 'Task excluída', text: $title);
    }

    #[On('task-created')]
    public function refreshTasks(): void
    {
        unset($this->tasks);
    }

    /**
     * Check if AI features are enabled and configured.
     */
    public function isAiEnabled(): bool
    {
        return app(AiAssistantService::class)->isEnabled();
    }

    /**
     * Analyze backlog tasks using the AI assistant.
     */
    public function analyzeBacklog(): void
    {
        if (! $this->isAiEnabled()) {
            return;
        }

        $cacheKey = 'ai_backlog_'.(auth()->id() ?? 'guest');

        if (Cache::has($cacheKey)) {
            Flux::toast(variant: 'warning', heading: 'Aguarde', text: 'Aguarde 1 minuto antes de solicitar nova análise.');

            return;
        }

        $this->aiLoading = true;

        try {
            $inboxTasks = Activity::query()
                ->whereIn('status', [ActivityStatus::Inbox, ActivityStatus::Backlog])
                ->with('project')
                ->get();

            $service = app(AiAssistantService::class);
            $result = $service->analyzeBacklog($inboxTasks);

            $this->backlogAnalysis = $result;
            $this->showBacklogAnalysis = true;

            Cache::put($cacheKey, true, now()->addMinute());
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', heading: 'Erro', text: 'Não foi possível analisar o backlog. Tente novamente.');
        } finally {
            $this->aiLoading = false;
        }
    }

    /**
     * Apply a backlog suggestion to a task.
     */
    public function applyBacklogSuggestion(int $taskId, string $action): void
    {
        $task = Activity::find($taskId);

        if (! $task) {
            return;
        }

        match ($action) {
            'archive' => $task->update(['status' => ActivityStatus::Done, 'completed_at' => now()]),
            'prioritize' => $this->applyPrioritySuggestion($task, $taskId),
            'estimate' => $task->update(['status' => ActivityStatus::Todo]),
            default => null,
        };

        $this->backlogAnalysis = array_values(
            array_filter($this->backlogAnalysis, fn (array $a): bool => $a['task_id'] !== $taskId)
        );

        unset($this->tasks);

        Flux::toast(variant: 'success', heading: 'Sugestão aplicada', text: $task->title);
    }

    /**
     * Apply priority suggestion from AI analysis.
     */
    private function applyPrioritySuggestion(Activity $task, int $taskId): void
    {
        $suggestion = collect($this->backlogAnalysis)->firstWhere('task_id', $taskId);
        $priority = $suggestion['suggested_priority'] ?? null;

        $priorityEnum = $priority ? ActivityPriority::tryFrom($priority) : null;

        $task->update([
            'status' => ActivityStatus::Todo,
            'priority' => $priorityEnum ?? $task->priority,
        ]);
    }

    /**
     * Dismiss a backlog suggestion without applying it.
     */
    public function dismissBacklogSuggestion(int $taskId): void
    {
        $this->backlogAnalysis = array_values(
            array_filter($this->backlogAnalysis, fn (array $a): bool => $a['task_id'] !== $taskId)
        );

        if (empty($this->backlogAnalysis)) {
            $this->showBacklogAnalysis = false;
        }
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Caixa de Entrada</flux:heading>

        @if ($this->isAiEnabled())
            <flux:button
                variant="subtle"
                icon="sparkles"
                wire:click="analyzeBacklog"
                wire:loading.attr="disabled"
                wire:target="analyzeBacklog"
                size="sm"
            >
                <span wire:loading.remove wire:target="analyzeBacklog">Analisar pendentes</span>
                <span wire:loading wire:target="analyzeBacklog">Analisando...</span>
            </flux:button>
        @endif
    </div>

    <div class="flex items-center gap-3">
        <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="Buscar tasks..." size="sm" class="max-w-xs" />
    </div>

    @if ($this->tasks->isEmpty())
        <div class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <flux:icon name="inbox" class="mx-auto mb-3 size-12 text-zinc-500" />
                <flux:heading size="lg">Nenhuma task no inbox</flux:heading>
                <flux:text class="mt-1">Pressione <kbd class="rounded border border-zinc-600 bg-zinc-700 px-1.5 py-0.5 text-xs">N</kbd> para criar.</flux:text>
            </div>
        </div>
    @else
        {{-- Bulk Actions Bar --}}
        @if (count($selectedTasks) > 0)
            <div class="flex items-center gap-3 rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2.5">
                <flux:text class="text-sm font-medium text-zinc-300">
                    {{ count($selectedTasks) }} {{ count($selectedTasks) === 1 ? 'task selecionada' : 'tasks selecionadas' }}
                </flux:text>

                <flux:separator vertical class="h-4" />

                <flux:dropdown>
                    <flux:button variant="ghost" size="sm" icon-trailing="chevron-down">
                        Mover para
                    </flux:button>
                    <flux:menu>
                        @foreach ($this->availableStatuses() as $status)
                            <flux:menu.item
                                wire:click="bulkMoveToStatus('{{ $status->value }}')"
                                icon="{{ $status->icon() }}"
                            >
                                {{ $status->label() }}
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>

                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="trash"
                    wire:click="confirmBulkDelete"
                    class="text-red-400 hover:text-red-300"
                >
                    Excluir
                </flux:button>

                <div class="ml-auto">
                    <flux:button variant="ghost" size="sm" wire:click="deselectAll">
                        Limpar seleção
                    </flux:button>
                </div>
            </div>
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-10">
                    @php
                        $allSelected = count($selectedTasks) === $this->tasks->count() && $this->tasks->count() > 0;
                        $someSelected = count($selectedTasks) > 0 && count($selectedTasks) < $this->tasks->count();
                    @endphp
                    <flux:checkbox
                        :checked="$allSelected"
                        :indeterminate="$someSelected"
                        wire:click="{{ $allSelected ? 'deselectAll' : 'selectAll' }}"
                    />
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'title'" :direction="$sortDirection" wire:click="sort('title')">Task</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'priority'" :direction="$sortDirection" wire:click="sort('priority')">Prioridade</flux:table.column>
                <flux:table.column>Projeto</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Criada</flux:table.column>
                <flux:table.column align="end">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->tasks as $task)
                    <flux:table.row :key="$task->id">
                        <flux:table.cell>
                            <flux:checkbox wire:model.live="selectedTasks" value="{{ $task->id }}" />
                        </flux:table.cell>
                        <flux:table.cell variant="strong">
                            {{ $task->title }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:select
                                wire:change="updatePriority({{ $task->id }}, $event.target.value)"
                                size="sm"
                                class="min-w-32"
                            >
                                @foreach (ActivityPriority::cases() as $priority)
                                    <option
                                        value="{{ $priority->value }}"
                                        @selected($task->priority === $priority)
                                    >
                                        {{ $priority->label() }}
                                    </option>
                                @endforeach
                            </flux:select>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:select
                                wire:change="assignProject({{ $task->id }}, $event.target.value)"
                                size="sm"
                                class="min-w-40"
                            >
                                <option value="">Sem projeto</option>
                                @foreach ($this->projects as $project)
                                    <option
                                        value="{{ $project->id }}"
                                        @selected($task->project_id === $project->id)
                                    >
                                        {{ $project->emoji }} {{ $project->name }}
                                    </option>
                                @endforeach
                            </flux:select>
                        </flux:table.cell>

                        <flux:table.cell>
                            {{ $task->created_at->diffForHumans() }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon-trailing="chevron-down">
                                        Mover
                                    </flux:button>
                                    <flux:menu>
                                        @foreach ($this->availableStatuses() as $status)
                                            <flux:menu.item
                                                wire:click="moveToStatus({{ $task->id }}, '{{ $status->value }}')"
                                                icon="{{ $status->icon() }}"
                                            >
                                                {{ $status->label() }}
                                            </flux:menu.item>
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="confirmDelete({{ $task->id }})"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Delete confirmation modal --}}
    <flux:modal wire:model.self="showDeleteModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Excluir task</flux:heading>
                <flux:text class="mt-1">
                    Tem certeza que deseja excluir <strong>{{ $deletingTaskTitle }}</strong>? Esta ação não pode ser desfeita.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteTask" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="deleteTask">Excluir</span>
                    <span wire:loading wire:target="deleteTask">Excluindo...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Bulk Delete confirmation modal --}}
    <flux:modal wire:model.self="showBulkDeleteModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Excluir tasks</flux:heading>
                <flux:text class="mt-1">
                    Tem certeza que deseja excluir <strong>{{ count($selectedTasks) }} tasks</strong>? Esta ação não pode ser desfeita.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="bulkDelete" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="bulkDelete">Excluir {{ count($selectedTasks) }}</span>
                    <span wire:loading wire:target="bulkDelete">Excluindo...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- AI Backlog Analysis Modal --}}
    @if ($this->isAiEnabled())
        <flux:modal wire:model.self="showBacklogAnalysis" class="md:w-[32rem]">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Análise de Pendentes</flux:heading>
                    <flux:text class="mt-1">Sugestões do AI Coach para organizar suas tasks.</flux:text>
                </div>

                @if ($aiLoading)
                    <div class="space-y-3">
                        @for ($i = 0; $i < 3; $i++)
                            <div class="rounded-lg border border-zinc-700 p-3">
                                <flux:skeleton class="mb-2 h-4 w-3/4" />
                                <flux:skeleton class="mb-2 h-3 w-full" />
                                <flux:skeleton class="h-3 w-1/4" />
                            </div>
                        @endfor
                    </div>
                @elseif (empty($backlogAnalysis))
                    <div class="py-8 text-center">
                        <flux:icon name="sparkles" class="mx-auto mb-2 size-8 text-zinc-600" />
                        <flux:text class="text-sm text-zinc-500">Nenhuma sugestão disponível no momento.</flux:text>
                    </div>
                @else
                    @php
                        $analysisTaskIds = array_column($backlogAnalysis, 'task_id');
                        $analysisTasks = \App\Models\Activity::whereIn('id', $analysisTaskIds)->get()->keyBy('id');
                    @endphp

                    <div class="max-h-96 space-y-3 overflow-y-auto">
                        @foreach ($backlogAnalysis as $analysis)
                            @php
                                $task = $analysisTasks->get($analysis['task_id']);
                                $action = $analysis['action'] ?? 'estimate';
                                $actionColor = match ($action) {
                                    'prioritize' => 'amber',
                                    'archive' => 'red',
                                    'estimate' => 'blue',
                                    default => 'zinc',
                                };
                                $actionLabel = match ($action) {
                                    'prioritize' => 'Priorizar',
                                    'archive' => 'Arquivar',
                                    'estimate' => 'Estimar',
                                    default => ucfirst($action),
                                };
                            @endphp

                            @if ($task)
                                <div wire:key="analysis-{{ $analysis['task_id'] }}" class="rounded-lg border border-zinc-700 p-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="truncate text-sm font-medium text-zinc-200">{{ $task->title }}</span>
                                                <flux:badge size="sm" color="{{ $actionColor }}">{{ $actionLabel }}</flux:badge>
                                            </div>
                                            <flux:text class="mt-1 text-xs text-zinc-400">{{ $analysis['reason'] }}</flux:text>

                                            @if ($analysis['suggested_priority'] ?? null)
                                                <flux:text class="mt-1 text-xs text-zinc-500">
                                                    Prioridade sugerida: {{ ucfirst($analysis['suggested_priority']) }}
                                                </flux:text>
                                            @endif
                                        </div>

                                        <div class="flex shrink-0 items-center gap-1">
                                            <flux:button
                                                wire:click="applyBacklogSuggestion({{ $task->id }}, '{{ $action }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="applyBacklogSuggestion({{ $task->id }}, '{{ $action }}')"
                                                size="sm"
                                                variant="ghost"
                                                icon="check"
                                            />
                                            <flux:button
                                                wire:click="dismissBacklogSuggestion({{ $task->id }})"
                                                wire:loading.attr="disabled"
                                                size="sm"
                                                variant="ghost"
                                                icon="x-mark"
                                                class="text-zinc-500"
                                            />
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <div class="flex justify-end border-t border-zinc-700 pt-4">
                        <flux:modal.close>
                            <flux:button variant="ghost">Fechar</flux:button>
                        </flux:modal.close>
                    </div>
                @endif
            </div>
        </flux:modal>
    @endif
</div>
