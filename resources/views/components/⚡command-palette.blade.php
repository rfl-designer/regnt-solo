<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Enums\ActivityType;
use App\Exceptions\WaitingRequiresWaitingForException;
use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    /**
     * Get search results based on the current query.
     *
     * @return array{tasks: \Illuminate\Support\Collection, projects: \Illuminate\Support\Collection, commands: array<int, array{label: string, description: string, icon: string, action: string}>}
     */
    #[Computed]
    public function results(): array
    {
        if (trim($this->search) === '') {
            return ['tasks' => collect(), 'projects' => collect(), 'commands' => []];
        }

        if (str_starts_with($this->search, '>')) {
            return ['tasks' => collect(), 'projects' => collect(), 'commands' => $this->parseCommand()];
        }

        return [
            'tasks' => $this->searchTasks(),
            'projects' => $this->searchProjects(),
            'commands' => [],
        ];
    }

    /**
     * Navigate to a task by opening the task modal.
     */
    public function openTask(int $taskId): void
    {
        $this->dispatch('open-task-modal', taskId: $taskId);
        $this->resetSearch();
        Flux::modal('command-palette')->close();
    }

    /**
     * Navigate to a project detail page.
     */
    public function openProject(string $slug): void
    {
        $this->resetSearch();
        Flux::modal('command-palette')->close();
        $this->redirect(route('project.detail', $slug), navigate: true);
    }

    /**
     * Execute a command action.
     */
    public function executeCommand(string $action): void
    {
        $parts = explode(':', $action, 3);
        $command = $parts[0] ?? '';
        $taskId = isset($parts[1]) ? (int) $parts[1] : null;
        $param = $parts[2] ?? null;

        if ($taskId === null || $taskId === 0) {
            Flux::toast(variant: 'warning', heading: 'Comando inválido', text: 'Task não encontrada.');

            return;
        }

        $task = Activity::find($taskId);

        if (! $task) {
            Flux::toast(variant: 'warning', heading: 'Task não encontrada', text: "Task #{$taskId} não existe.");

            return;
        }

        match ($command) {
            'mover' => $this->moveTask($task, $param),
            'timer' => $this->toggleTimer($task),
            'deletar' => $this->deleteTask($task),
            'projeto' => $this->assignProject($task, $param),
            'classe' => $this->changeServiceClass($task, $param),
            'planejar' => $this->planTask($task),
            default => Flux::toast(variant: 'warning', heading: 'Comando desconhecido', text: "Comando '{$command}' não reconhecido."),
        };

        $this->resetSearch();
        Flux::modal('command-palette')->close();
    }

    /**
     * Search tasks (Issue + Task types) by title.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    private function searchTasks(): \Illuminate\Database\Eloquent\Collection
    {
        return Activity::query()
            ->whereIn('type', [ActivityType::Issue, ActivityType::Task])
            ->with('project')
            ->where('title', 'like', '%'.trim($this->search).'%')
            ->orderByRaw("CASE status WHEN 'doing' THEN 1 WHEN 'todo' THEN 2 WHEN 'backlog' THEN 3 WHEN 'inbox' THEN 4 WHEN 'done' THEN 5 END")
            ->limit(10)
            ->get();
    }

    /**
     * Search projects by name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    private function searchProjects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::query()
            ->where('name', 'like', '%'.trim($this->search).'%')
            ->orderBy('name')
            ->limit(5)
            ->get();
    }

    /**
     * Parse a command string and return matching command suggestions.
     *
     * @return array<int, array{label: string, description: string, icon: string, action: string}>
     */
    private function parseCommand(): array
    {
        $raw = trim(mb_substr($this->search, 1));

        if ($raw === '') {
            return $this->availableCommands();
        }

        $parts = preg_split('/\s+/', $raw, 3);
        $verb = mb_strtolower($parts[0] ?? '');
        $taskSearch = $parts[1] ?? '';
        $param = $parts[2] ?? '';

        $matchingVerbs = collect($this->availableCommands())
            ->filter(fn (array $cmd) => str_contains(mb_strtolower($cmd['label']), $verb));

        if ($matchingVerbs->isEmpty()) {
            return [];
        }

        if ($taskSearch === '') {
            return $matchingVerbs->values()->all();
        }

        $tasks = Activity::query()
            ->whereIn('type', [ActivityType::Issue, ActivityType::Task])
            ->where('title', 'like', '%'.$taskSearch.'%')
            ->limit(8)
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        $results = [];

        foreach ($matchingVerbs as $cmd) {
            foreach ($tasks as $task) {
                $action = $verb.':'.$task->id;

                if ($param !== '') {
                    $action .= ':'.$param;
                }

                $results[] = [
                    'label' => $cmd['label'].' "'.$task->title.'"'.($param !== '' ? ' → '.$param : ''),
                    'description' => $task->status->label().' · '.($task->project?->name ?? 'Sem projeto'),
                    'icon' => $cmd['icon'],
                    'action' => $action,
                ];
            }
        }

        return array_slice($results, 0, 10);
    }

    /**
     * Get the list of available commands.
     *
     * @return array<int, array{label: string, description: string, icon: string, action: string}>
     */
    private function availableCommands(): array
    {
        return [
            ['label' => 'mover', 'description' => 'Mover task para outro status', 'icon' => 'arrow-right', 'action' => ''],
            ['label' => 'timer', 'description' => 'Iniciar/parar timer da task', 'icon' => 'clock', 'action' => ''],
            ['label' => 'deletar', 'description' => 'Excluir task (com confirmação)', 'icon' => 'trash', 'action' => ''],
            ['label' => 'projeto', 'description' => 'Atribuir task a um projeto', 'icon' => 'folder', 'action' => ''],
            ['label' => 'classe', 'description' => 'Alterar classe de serviço da task', 'icon' => 'flag', 'action' => ''],
            ['label' => 'planejar', 'description' => 'Adicionar task ao plano de hoje', 'icon' => 'calendar-days', 'action' => ''],
        ];
    }

    /**
     * Move a task to a new status.
     *
     * Moving into the internal wait (Esperando) has no client to fall back
     * on for "esperando quem", so it's deferred to the same blocking mini
     * modal used by the Kanban drag-and-drop rather than risking the
     * domain guard's refusal on a direct update. Moving into a client-side
     * wait (Aguardando aprovação/validação) is applied directly — the
     * guard auto-fills it from the effective client — but a refusal (no
     * effective client to resolve) is still caught and surfaced with the
     * canonical message instead of failing as a raw Livewire error.
     */
    private function moveTask(Activity $task, ?string $statusValue): void
    {
        $status = ActivityStatus::tryFrom($statusValue ?? '');

        if (! $status) {
            Flux::toast(variant: 'warning', heading: 'Status inválido', text: 'Use: inbox, backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done');

            return;
        }

        if ($status->isInternalWaiting() && $task->status !== ActivityStatus::Waiting) {
            $this->dispatch('open-waiting-for-modal', taskId: $task->id, status: $status->value);

            return;
        }

        try {
            if ($status === ActivityStatus::Done) {
                $task->markAsDone();
            } else {
                $task->update(['status' => $status, 'completed_at' => null]);
            }
        } catch (WaitingRequiresWaitingForException|\App\Exceptions\DoingWipLimitExceededException $e) {
            Flux::toast(variant: 'danger', heading: 'Não foi possível mover', text: $e->getMessage());

            return;
        }

        $this->dispatch('task-updated');
        Flux::toast(variant: 'success', heading: 'Task movida', text: "{$task->title} → {$status->label()}");
    }

    /**
     * Toggle the timer for a task.
     */
    private function toggleTimer(Activity $task): void
    {
        $running = TimeEntry::query()
            ->where('activity_id', $task->id)
            ->running()
            ->first();

        if ($running) {
            $running->update(['stopped_at' => now()]);
            Flux::toast(variant: 'success', heading: 'Timer parado', text: $task->title);
        } else {
            $task->startTimer();
            Flux::toast(variant: 'success', heading: 'Timer iniciado', text: $task->title);
        }

        $this->dispatch('timer-updated');
    }

    /**
     * Delete a task.
     */
    private function deleteTask(Activity $task): void
    {
        $title = $task->title;
        $task->delete();

        $this->dispatch('task-updated');
        Flux::toast(variant: 'success', heading: 'Task excluída', text: $title);
    }

    /**
     * Assign a task to a project by slug.
     */
    private function assignProject(Activity $task, ?string $projectSlug): void
    {
        if (! $projectSlug) {
            Flux::toast(variant: 'warning', heading: 'Projeto não informado', text: 'Informe o slug do projeto.');

            return;
        }

        $project = Project::where('slug', $projectSlug)->first();

        if (! $project) {
            Flux::toast(variant: 'warning', heading: 'Projeto não encontrado', text: "Projeto '{$projectSlug}' não existe.");

            return;
        }

        $task->update(['project_id' => $project->id]);

        $this->dispatch('task-updated');
        Flux::toast(variant: 'success', heading: 'Projeto atribuído', text: "{$task->title} → {$project->name}");
    }

    /**
     * Change the service class of a task.
     */
    private function changeServiceClass(Activity $task, ?string $serviceClassValue): void
    {
        $serviceClass = ServiceClass::tryFrom($serviceClassValue ?? '');

        if (! $serviceClass) {
            Flux::toast(variant: 'warning', heading: 'Classe de serviço inválida', text: 'Use: emergency, fixed_date, standard, intangible');

            return;
        }

        // Emergência needs a motivo, and possibly a decision about the one
        // already holding the board's slot — same blocking modal every other
        // surface defers to, rather than a refusal the palette can't resolve.
        if ($serviceClass === ServiceClass::Emergency) {
            $this->dispatch('open-emergency-modal', taskId: $task->id);

            return;
        }

        try {
            $task->update(['service_class' => $serviceClass]);
        } catch (\App\Exceptions\FixedDateRequiresDueDateException $e) {
            Flux::toast(variant: 'danger', heading: 'Não foi possível alterar', text: $e->getMessage());

            return;
        }

        $this->dispatch('task-updated');
        Flux::toast(variant: 'success', heading: 'Classe de serviço alterada', text: "{$task->title} → {$serviceClass->label()}");
    }

    /**
     * Add a task to today's daily plan.
     */
    private function planTask(Activity $task): void
    {
        $plan = DailyPlan::getOrCreateForDate(Carbon::today());

        if ($plan->tasks()->where('activity_id', $task->id)->exists()) {
            Flux::toast(variant: 'warning', heading: 'Já planejada', text: "{$task->title} já está no plano de hoje.");

            return;
        }

        $maxOrder = $plan->tasks()->max('daily_plan_activity.sort_order') ?? -1;
        $plan->tasks()->attach($task->id, ['sort_order' => $maxOrder + 1]);

        $this->dispatch('task-updated');
        Flux::toast(variant: 'success', heading: 'Task planejada', text: "{$task->title} adicionada ao plano de hoje.");
    }

    /**
     * Reset the search input.
     */
    private function resetSearch(): void
    {
        $this->search = '';
        unset($this->results);
    }
};

?>

<div>
    <flux:modal name="command-palette" variant="bare" class="w-full max-w-[32rem] my-[12vh] max-h-screen overflow-y-hidden">
        <flux:command class="border-none shadow-lg inline-flex flex-col max-h-[76vh]">
            <flux:command.input
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar tasks, projetos ou usar > para comandos..."
                closable
            />

            <flux:command.items>
                @php $results = $this->results; @endphp

                {{-- Empty state --}}
                @if ($search === '')
                    <div class="px-4 py-6 text-center text-sm text-zinc-500">
                        <p>Digite para buscar ou use <code class="rounded bg-zinc-700 px-1.5 py-0.5 text-xs text-zinc-300">></code> para comandos</p>
                        <div class="mt-3 flex flex-wrap justify-center gap-2">
                            <flux:badge size="sm" color="zinc">N</flux:badge> <span class="text-xs">Nova task</span>
                            <flux:badge size="sm" color="zinc">B</flux:badge> <span class="text-xs">Kanban</span>
                            <flux:badge size="sm" color="zinc">F</flux:badge> <span class="text-xs">Work</span>
                            <flux:badge size="sm" color="zinc">D</flux:badge> <span class="text-xs">Daily</span>
                            <flux:badge size="sm" color="zinc">I</flux:badge> <span class="text-xs">Inbox</span>
                            <flux:badge size="sm" color="zinc">T</flux:badge> <span class="text-xs">Timer</span>
                        </div>
                    </div>
                @elseif (str_starts_with($search, '>'))
                    {{-- Command mode --}}
                    @if (trim($search) === '>')
                        {{-- Show syntax help when only > is typed --}}
                        <div class="px-4 py-3 text-sm text-zinc-400 border-b border-zinc-700">
                            <div class="mb-2 font-medium text-zinc-300">Exemplos de Sintaxe</div>
                            <div class="space-y-1.5 text-xs">
                                <div class="flex items-center gap-2">
                                    <code class="text-emerald-400">> mover "task" doing</code>
                                    <span class="text-zinc-600">•</span>
                                    <span class="text-zinc-500">inbox, backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <code class="text-emerald-400">> classe "task" emergency</code>
                                    <span class="text-zinc-600">•</span>
                                    <span class="text-zinc-500">emergency, fixed_date, standard, intangible</span>
                                </div>
                            </div>
                        </div>
                        {{-- Also show available commands --}}
                        @foreach ($results['commands'] as $cmd)
                            <flux:command.item
                                wire:key="cmd-{{ $loop->index }}"
                                wire:click="executeCommand('{{ $cmd['action'] }}')"
                                :icon="$cmd['icon']"
                            >
                                <div class="flex flex-col">
                                    <span>{{ $cmd['label'] }}</span>
                                    <span class="text-xs text-zinc-500">{{ $cmd['description'] }}</span>
                                </div>
                            </flux:command.item>
                        @endforeach
                    @elseif (empty($results['commands']))
                        <div class="px-4 py-6 text-center text-sm text-zinc-500">
                            Nenhum comando encontrado.
                            <div class="mt-2 text-xs">
                                Comandos: <code>mover</code>, <code>timer</code>, <code>deletar</code>, <code>projeto</code>, <code>classe</code>, <code>planejar</code>
                            </div>
                        </div>
                    @else
                        @foreach ($results['commands'] as $cmd)
                            <flux:command.item
                                wire:key="cmd-{{ $loop->index }}"
                                wire:click="executeCommand('{{ $cmd['action'] }}')"
                                :icon="$cmd['icon']"
                            >
                                <div class="flex flex-col">
                                    <span>{{ $cmd['label'] }}</span>
                                    <span class="text-xs text-zinc-500">{{ $cmd['description'] }}</span>
                                </div>
                            </flux:command.item>
                        @endforeach
                    @endif
                @else
                    {{-- Search mode --}}
                    @if ($results['tasks']->isEmpty() && $results['projects']->isEmpty())
                        <div class="px-4 py-6 text-center text-sm text-zinc-500">
                            Nenhum resultado para "{{ $search }}"
                        </div>
                    @endif

                    {{-- Tasks --}}
                    @if ($results['tasks']->isNotEmpty())
                        <div class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wider text-zinc-500">
                            Tasks
                        </div>
                        @foreach ($results['tasks'] as $task)
                            <flux:command.item
                                wire:key="task-{{ $task->id }}"
                                wire:click="openTask({{ $task->id }})"
                                :icon="$task->status->icon()"
                            >
                                <div class="flex flex-col">
                                    <span>{{ $task->title }}</span>
                                    <span class="text-xs text-zinc-500">
                                        {{ $task->status->label() }}
                                        @if ($task->project)
                                            · {{ $task->project->emoji }} {{ $task->project->name }}
                                        @endif
                                    </span>
                                </div>
                            </flux:command.item>
                        @endforeach
                    @endif

                    {{-- Projects --}}
                    @if ($results['projects']->isNotEmpty())
                        <div class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wider text-zinc-500">
                            Projetos
                        </div>
                        @foreach ($results['projects'] as $project)
                            <flux:command.item
                                wire:key="project-{{ $project->id }}"
                                wire:click="openProject('{{ $project->slug }}')"
                                icon="folder"
                            >
                                <div class="flex items-center gap-2">
                                    <span>{{ $project->emoji }} {{ $project->name }}</span>
                                    <flux:badge size="sm" color="{{ $project->status->color() }}">{{ $project->status->label() }}</flux:badge>
                                </div>
                            </flux:command.item>
                        @endforeach
                    @endif
                @endif
            </flux:command.items>
        </flux:command>
    </flux:modal>
</div>
