<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\ActivityCommit;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public bool $showDeleteConfirm = false;

    public ?int $taskId = null;

    public string $title = '';

    public ?string $projectId = null;

    public ?string $clientId = null;

    public string $priority = 'medium';

    public string $status = 'inbox';

    public ?string $dueDate = null;

    public ?int $estimatedMinutes = null;

    /** @var array<int, array{id: int, started_at: string, stopped_at: string|null, notes: string|null}> */
    public array $timeEntries = [];

    /** @var array<string, array{label: string, minutes: float, color: string, hex_color: string, percentage: float}> */
    public array $statusTimeSegments = [];

    public string $sessionPrompt = '';

    public string $sessionResult = '';

    public bool $editingPrompt = false;

    public string $prUrl = '';

    public bool $editingPrUrl = false;

    public string $activeTab = 'details';

    /** @var array<int, array{hash: string, short_hash: string, message: string, files_changed: int, insertions: int, deletions: int, committed_at: string}> */
    public array $commits = [];

    /** @var array<int, array{id: int, title: string, slug: string, type_label: string, type_icon: string, type_color: string}> */
    public array $projectDocuments = [];

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::active()->orderBy('name')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Client>
     */
    #[Computed]
    public function clients(): \Illuminate\Database\Eloquent\Collection
    {
        return Client::active()->orderBy('name')->get();
    }

    /**
     * The direct client select is only meaningful when the task has no project.
     */
    public function updatedProjectId(): void
    {
        if ($this->projectId) {
            $this->clientId = null;
        }
    }

    #[Computed]
    public function isSessionTask(): bool
    {
        return $this->sessionPrompt !== '';
    }

    #[Computed]
    public function prDisplayLabel(): string
    {
        if (preg_match('/\/pull\/(\d+)/', $this->prUrl, $matches)) {
            return 'PR #'.$matches[1];
        }

        return 'Ver PR';
    }

    #[On('open-task-modal')]
    public function openTask(int $taskId): void
    {
        $task = Activity::with(['timeEntries', 'statusChanges', 'commits'])->findOrFail($taskId);

        $this->taskId = $task->id;
        $this->title = $task->title;
        $this->projectId = $task->project_id ? (string) $task->project_id : null;
        $this->clientId = $task->client_id ? (string) $task->client_id : null;
        $this->priority = $task->priority->value;
        $this->status = $task->status->value;
        $this->dueDate = $task->due_date?->format('Y-m-d');
        $this->estimatedMinutes = $task->estimated_minutes;

        $this->timeEntries = $task->timeEntries
            ->sortByDesc('started_at')
            ->map(fn (TimeEntry $entry) => [
                'id' => $entry->id,
                'started_at' => $entry->started_at->format('Y-m-d\TH:i'),
                'stopped_at' => $entry->stopped_at?->format('Y-m-d\TH:i') ?? '',
                'notes' => $entry->notes ?? '',
                'duration_minutes' => $entry->duration_minutes,
                'is_focus_session' => (bool) $entry->is_focus_session,
                'focus_rating' => $entry->focus_rating,
            ])
            ->values()
            ->all();

        $this->statusTimeSegments = $this->buildStatusTimeSegments($task);

        $this->sessionPrompt = $task->session_prompt ?? '';
        $this->sessionResult = $task->session_result ?? '';

        $this->prUrl = $task->pr_url ?? '';

        $this->commits = $task->commits
            ->sortByDesc('committed_at')
            ->map(fn (ActivityCommit $commit) => [
                'hash' => $commit->hash,
                'short_hash' => substr($commit->hash, 0, 7),
                'message' => $commit->message,
                'files_changed' => $commit->files_changed,
                'insertions' => $commit->insertions,
                'deletions' => $commit->deletions,
                'committed_at' => $commit->committed_at->format('d/m H:i'),
            ])
            ->values()
            ->all();

        $this->projectDocuments = [];
        if ($task->project_id) {
            $this->projectDocuments = \App\Models\Document::query()
                ->forProject($task->project_id)
                ->ordered()
                ->take(5)
                ->get()
                ->map(fn (\App\Models\Document $doc) => [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'slug' => $doc->slug,
                    'type_label' => $doc->type->label(),
                    'type_icon' => $doc->type->icon(),
                    'type_color' => $doc->type->color(),
                ])
                ->all();
        }

        $this->showModal = true;
        $this->showDeleteConfirm = false;
        $this->editingPrompt = false;
        $this->editingPrUrl = false;
        $this->activeTab = 'details';
    }

    /**
     * Build the segmented bar data from the activity's time_in_status accessor.
     *
     * @return array<string, array{label: string, minutes: float, color: string, hex_color: string, percentage: float}>
     */
    private function buildStatusTimeSegments(Activity $task): array
    {
        if ($task->statusChanges->count() <= 1) {
            return [];
        }

        $timeInStatus = $task->time_in_status;
        $totalMinutes = array_sum($timeInStatus);

        if ($totalMinutes <= 0) {
            return [];
        }

        $segments = [];

        foreach (ActivityStatus::cases() as $status) {
            $minutes = $timeInStatus[$status->value] ?? 0.0;

            if ($minutes <= 0) {
                continue;
            }

            $segments[$status->value] = [
                'label' => $status->label(),
                'minutes' => $minutes,
                'color' => $status->color(),
                'hex_color' => $status->hexColor(),
                'percentage' => round(($minutes / $totalMinutes) * 100, 1),
            ];
        }

        return $segments;
    }

    /**
     * Format minutes into a human-readable duration string (Xd Xh Xm).
     */
    public static function formatDuration(float $minutes): string
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

    public function saveTask(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'projectId' => 'nullable|exists:projects,id',
            'clientId' => 'nullable|exists:clients,id',
            'priority' => 'required|in:'.implode(',', array_column(ActivityPriority::cases(), 'value')),
            'status' => 'required|in:'.implode(',', array_column(ActivityStatus::cases(), 'value')),
            'dueDate' => 'nullable|date',
            'estimatedMinutes' => 'nullable|integer|min:1',
        ]);

        $task = Activity::findOrFail($this->taskId);

        $newStatus = ActivityStatus::from($this->status);

        if ($newStatus === ActivityStatus::Done && $task->status !== ActivityStatus::Done) {
            $task->update([
                'title' => $this->title,
                'project_id' => $this->projectId ? (int) $this->projectId : null,
                'client_id' => $this->projectId ? null : ($this->clientId ? (int) $this->clientId : null),
                'priority' => $this->priority,
                'due_date' => $this->dueDate ?: null,
                'estimated_minutes' => $this->estimatedMinutes,
                'pr_url' => $this->prUrl ?: null,
                'session_prompt' => $this->sessionPrompt ?: null,
                'session_result' => $this->sessionResult ?: null,
            ]);
            $task->markAsDone();
            $this->status = ActivityStatus::Done->value;
        } else {
            $task->update([
                'title' => $this->title,
                'project_id' => $this->projectId ? (int) $this->projectId : null,
                'client_id' => $this->projectId ? null : ($this->clientId ? (int) $this->clientId : null),
                'status' => $this->status,
                'priority' => $this->priority,
                'due_date' => $this->dueDate ?: null,
                'estimated_minutes' => $this->estimatedMinutes,
                'completed_at' => $newStatus === ActivityStatus::Done ? $task->completed_at : null,
                'pr_url' => $this->prUrl ?: null,
                'session_prompt' => $this->sessionPrompt ?: null,
                'session_result' => $this->sessionResult ?: null,
            ]);
        }

        foreach ($this->timeEntries as $entryData) {
            $entry = TimeEntry::find($entryData['id']);
            if ($entry && $entry->activity_id === $this->taskId) {
                $data = [
                    'started_at' => $entryData['started_at'] ?: null,
                    'notes' => $entryData['notes'] ?: null,
                ];

                // Only update stopped_at if user provided a value, to avoid
                // overwriting values set by markAsDone on running entries
                if ($entryData['stopped_at'] !== '') {
                    $data['stopped_at'] = $entryData['stopped_at'];
                }

                $entry->update($data);
            }
        }

        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Task salva', text: $this->title);
    }

    public function deleteTimeEntry(int $entryId): void
    {
        $entry = TimeEntry::where('activity_id', $this->taskId)->findOrFail($entryId);
        $entry->delete();

        $this->timeEntries = collect($this->timeEntries)
            ->reject(fn (array $e) => $e['id'] === $entryId)
            ->values()
            ->all();

        Flux::toast(text: 'Entrada de tempo removida com sucesso.', variant: 'success', heading: 'Entrada removida');
    }

    public function confirmDelete(): void
    {
        $this->showDeleteConfirm = true;
    }

    public function deleteTask(): void
    {
        if ($this->taskId === null) {
            return;
        }

        $task = Activity::findOrFail($this->taskId);
        $title = $task->title;
        $task->delete();

        $this->showDeleteConfirm = false;
        $this->showModal = false;
        $this->reset('taskId', 'title', 'projectId', 'clientId', 'priority', 'status', 'dueDate', 'estimatedMinutes', 'timeEntries', 'prUrl', 'editingPrUrl', 'activeTab', 'commits', 'sessionPrompt', 'sessionResult', 'projectDocuments');

        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Task excluída', text: $title);
    }
};

?>

<div>
    <flux:modal wire:model.self="showModal" class="w-3xl max-h-[90vh] overflow-y-auto">
        <div class="space-y-6">
            {{-- Header --}}
            <div>
                <flux:heading size="lg">Editar Task</flux:heading>
                <flux:text class="mt-1">Atualize os detalhes da tarefa.</flux:text>
            </div>

            {{-- Tabs --}}
            @php
                $hasSessionContent = $this->isSessionTask || count($timeEntries) > 0 || count($commits) > 0 || $prUrl || count($statusTimeSegments) > 0 || count($projectDocuments) > 0;
            @endphp

            <flux:tab.group>
                <flux:tabs wire:model="activeTab" variant="segmented" size="sm">
                    <flux:tab name="details" icon="clipboard-document-list">Detalhes</flux:tab>
                    <flux:tab name="session" icon="command-line">
                        Sessão & Git
                        @if ($this->isSessionTask)
                            <flux:badge size="sm" color="violet" class="ml-1">AI</flux:badge>
                        @endif
                    </flux:tab>
                </flux:tabs>

                {{-- Tab: Detalhes --}}
                <flux:tab.panel name="details" class="space-y-6 pt-4">
                    {{-- Title --}}
                    <flux:input
                        wire:model="title"
                        label="Título"
                        placeholder="Título da tarefa"
                    />

                    {{-- Session Prompt / Descrição --}}
                    <flux:field>
                        <div class="flex items-center justify-between">
                            <flux:label>{{ $this->isSessionTask ? 'Instruções AI' : 'Descrição' }}</flux:label>
                            @if (!($status === 'done' && $this->isSessionTask))
                                <flux:button
                                    wire:click="$toggle('editingPrompt')"
                                    variant="ghost"
                                    size="xs"
                                    :icon="$editingPrompt ? 'eye' : 'pencil'"
                                />
                            @endif
                        </div>

                        @if ($editingPrompt && !($status === 'done' && $this->isSessionTask))
                            {{-- Modo Edição --}}
                            <flux:editor
                                wire:model="sessionPrompt"
                                placeholder="Descreva a tarefa..."
                            />
                        @else
                            {{-- Modo Visualização --}}
                            @if ($sessionPrompt)
                                <div class="max-h-60 overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-800/50 p-3">
                                    <div class="prose prose-sm prose-invert max-w-none prose-headings:text-zinc-200 prose-p:text-zinc-300 prose-a:text-blue-400 prose-strong:text-zinc-200 prose-code:text-pink-400 prose-pre:bg-zinc-900 prose-li:text-zinc-300">
                                        {!! Str::markdown($sessionPrompt) !!}
                                    </div>
                                </div>
                            @else
                                <div class="flex items-center justify-center rounded-lg border border-dashed border-zinc-700 bg-zinc-800/30 p-6 text-zinc-500">
                                    <span class="text-sm">Clique no ícone de editar para adicionar uma descrição</span>
                                </div>
                            @endif
                        @endif
                    </flux:field>

                    {{-- Two-column grid for selects --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- Project --}}
                        <div>
                            <flux:select wire:model.live="projectId" label="Projeto">
                                <flux:select.option value="">Sem projeto</flux:select.option>
                                @foreach ($this->projects as $project)
                                    <flux:select.option :value="$project->id" wire:key="project-{{ $project->id }}">
                                        {{ $project->emoji }} {{ $project->name }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                            <div class="mt-1 h-4"></div>
                        </div>

                        {{-- Client (only when there is no project) --}}
                        <div>
                            <flux:select
                                wire:model="clientId"
                                label="Cliente"
                                :disabled="filled($projectId)"
                            >
                                <flux:select.option value="">Sem cliente</flux:select.option>
                                @foreach ($this->clients as $client)
                                    <flux:select.option :value="$client->id" wire:key="client-{{ $client->id }}">
                                        {{ $client->name }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                            <div class="mt-1 h-4 text-xs text-zinc-500">
                                @if (filled($projectId))
                                    Herdado do projeto
                                @endif
                            </div>
                        </div>

                        {{-- Priority --}}
                        <flux:select wire:model="priority" label="Prioridade">
                            @foreach (ActivityPriority::cases() as $p)
                                <flux:select.option :value="$p->value">
                                    {{ $p->label() }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        {{-- Status --}}
                        <flux:select wire:model="status" label="Status">
                            @foreach (ActivityStatus::cases() as $s)
                                <flux:select.option :value="$s->value">
                                    {{ $s->label() }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        {{-- Due Date --}}
                        <flux:date-picker wire:model="dueDate" label="Prazo" clearable />
                    </div>

                    {{-- Estimated Minutes --}}
                    <flux:input
                        wire:model="estimatedMinutes"
                        type="number"
                        label="Estimativa (min)"
                        placeholder="Ex: 60"
                        min="1"
                    />
                </flux:tab.panel>

                {{-- Tab: Sessão & Git --}}
                <flux:tab.panel name="session" class="space-y-6 pt-4">
                    {{-- Sessão de Desenvolvimento --}}
                    @if ($this->isSessionTask)
                        @php
                            $totalMinutes = collect($timeEntries)->sum('duration_minutes');
                            $commitsCount = count($commits);
                            $focusMinutes = collect($timeEntries)->where('is_focus_session', true)->sum('duration_minutes');
                            $isDone = $status === ActivityStatus::Done->value;
                        @endphp
                        <div class="space-y-4">
                            <flux:heading size="sm" class="flex items-center gap-2">
                                <flux:icon name="command-line" variant="mini" class="text-violet-400" />
                                Sessão de Desenvolvimento
                            </flux:heading>

                            {{-- Resultado da Sessão --}}
                            @if ($sessionResult || $isDone)
                                <flux:field>
                                    <flux:label>Resultado da Sessão</flux:label>
                                    @if ($isDone && $sessionResult)
                                        <div class="max-h-40 overflow-y-auto rounded-lg border border-zinc-700 bg-zinc-800/50 p-3">
                                            <div class="markdown-viewer prose-sm">
                                                {!! \App\Support\Markdown::render($sessionResult) !!}
                                            </div>
                                        </div>
                                    @else
                                        <flux:textarea wire:model="sessionResult" rows="3" placeholder="Resumo do que foi implementado..." />
                                    @endif
                                </flux:field>
                            @endif

                            {{-- Timeline da Sessão --}}
                            <div class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-700 bg-zinc-800/50 p-3">
                                {{-- Prompt --}}
                                <div class="flex items-center gap-1.5">
                                    <div class="flex h-6 w-6 items-center justify-center rounded-full {{ $sessionPrompt ? 'bg-violet-500/20 text-violet-400' : 'bg-zinc-700 text-zinc-500' }}">
                                        <flux:icon name="document-text" variant="micro" />
                                    </div>
                                    <span class="text-xs {{ $sessionPrompt ? 'text-zinc-300' : 'text-zinc-500' }}">Prompt</span>
                                </div>

                                <flux:icon name="chevron-right" variant="micro" class="text-zinc-600" />

                                {{-- Timer --}}
                                <div class="flex items-center gap-1.5">
                                    <div class="flex h-6 w-6 items-center justify-center rounded-full {{ $totalMinutes > 0 ? 'bg-blue-500/20 text-blue-400' : 'bg-zinc-700 text-zinc-500' }}">
                                        <flux:icon name="clock" variant="micro" />
                                    </div>
                                    <span class="text-xs {{ $totalMinutes > 0 ? 'text-zinc-300' : 'text-zinc-500' }}">
                                        {{ $totalMinutes > 0 ? round($totalMinutes / 60, 1) . 'h' : 'Timer' }}
                                    </span>
                                </div>

                                <flux:icon name="chevron-right" variant="micro" class="text-zinc-600" />

                                {{-- Commits --}}
                                <div class="flex items-center gap-1.5">
                                    <div class="flex h-6 w-6 items-center justify-center rounded-full {{ $commitsCount > 0 ? 'bg-green-500/20 text-green-400' : 'bg-zinc-700 text-zinc-500' }}">
                                        <flux:icon name="code-bracket" variant="micro" />
                                    </div>
                                    <span class="text-xs {{ $commitsCount > 0 ? 'text-zinc-300' : 'text-zinc-500' }}">
                                        {{ $commitsCount > 0 ? $commitsCount . ' commits' : 'Commits' }}
                                    </span>
                                </div>

                                <flux:icon name="chevron-right" variant="micro" class="text-zinc-600" />

                                {{-- PR --}}
                                <div class="flex items-center gap-1.5">
                                    <div class="flex h-6 w-6 items-center justify-center rounded-full {{ $prUrl ? 'bg-orange-500/20 text-orange-400' : 'bg-zinc-700 text-zinc-500' }}">
                                        <flux:icon name="arrow-up-on-square" variant="micro" />
                                    </div>
                                    @if ($prUrl)
                                        <a href="{{ $prUrl }}" target="_blank" class="text-xs text-orange-400 hover:underline">PR</a>
                                    @else
                                        <span class="text-xs text-zinc-500">PR</span>
                                    @endif
                                </div>

                                <flux:icon name="chevron-right" variant="micro" class="text-zinc-600" />

                                {{-- Done --}}
                                <div class="flex items-center gap-1.5">
                                    <div class="flex h-6 w-6 items-center justify-center rounded-full {{ $isDone ? 'bg-emerald-500/20 text-emerald-400' : 'bg-zinc-700 text-zinc-500' }}">
                                        <flux:icon name="check" variant="micro" />
                                    </div>
                                    <span class="text-xs {{ $isDone ? 'text-emerald-400' : 'text-zinc-500' }}">Done</span>
                                </div>

                                {{-- Focus badge --}}
                                @if ($focusMinutes > 0)
                                    <flux:badge size="sm" color="amber" class="ml-auto">
                                        🎯 {{ round($focusMinutes / 60, 1) }}h de focus
                                    </flux:badge>
                                @endif
                            </div>
                        </div>

                        <flux:separator />
                    @endif

                    {{-- Documentos do Projeto --}}
                    @if (count($projectDocuments) > 0)
                        <div class="space-y-3">
                            <flux:heading size="sm" class="flex items-center gap-2">
                                <flux:icon name="document-text" variant="mini" class="text-indigo-400" />
                                Documentos do Projeto
                            </flux:heading>

                            <div class="space-y-1.5">
                                @foreach ($projectDocuments as $doc)
                                    <a
                                        href="{{ route('document.view', $doc['slug']) }}"
                                        wire:navigate
                                        class="flex items-center gap-2.5 rounded-lg border border-zinc-700/50 bg-zinc-800/30 px-3 py-2 transition hover:border-zinc-600 hover:bg-zinc-800/50"
                                    >
                                        <flux:icon :name="$doc['type_icon']" class="size-4 text-{{ $doc['type_color'] }}-400" />
                                        <span class="flex-1 truncate text-sm text-zinc-300">{{ $doc['title'] }}</span>
                                        <flux:badge size="sm" :color="$doc['type_color']">{{ $doc['type_label'] }}</flux:badge>
                                    </a>
                                @endforeach
                            </div>
                        </div>

                        <flux:separator />
                    @endif

                    {{-- Time Entries Section --}}
                    @if (count($timeEntries) > 0)
                        <div class="space-y-3">
                            <flux:heading size="sm" class="flex items-center gap-2">
                                <flux:icon name="clock" variant="mini" class="text-blue-400" />
                                Entradas de Tempo
                            </flux:heading>

                            <div class="space-y-2">
                                @foreach ($timeEntries as $index => $entry)
                                    <div wire:key="entry-{{ $entry['id'] }}" class="flex items-start gap-2 rounded-lg border border-zinc-700 bg-zinc-800/50 p-3">
                                        <div class="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-2">
                                            <flux:input
                                                wire:model="timeEntries.{{ $index }}.started_at"
                                                type="datetime-local"
                                                label="Início"
                                                size="sm"
                                            />
                                            <flux:input
                                                wire:model="timeEntries.{{ $index }}.stopped_at"
                                                type="datetime-local"
                                                label="Fim"
                                                size="sm"
                                            />
                                            <flux:input
                                                wire:model="timeEntries.{{ $index }}.notes"
                                                label="Notas"
                                                placeholder="Notas..."
                                                size="sm"
                                                class="sm:col-span-2"
                                            />
                                        </div>
                                        <div class="flex flex-col items-center gap-1 pt-6">
                                            <flux:badge size="sm" color="zinc">
                                                {{ round($entry['duration_minutes']) }}min
                                            </flux:badge>
                                            @if ($entry['is_focus_session'])
                                                <flux:badge size="sm" color="amber">
                                                    🎯
                                                </flux:badge>
                                                @if ($entry['focus_rating'])
                                                    <span class="text-xs text-amber-400">{{ $entry['focus_rating'] }}⭐</span>
                                                @endif
                                            @endif
                                            <flux:button
                                                wire:click="deleteTimeEntry({{ $entry['id'] }})"
                                                variant="ghost"
                                                size="xs"
                                                icon="trash"
                                                wire:confirm="Remover esta entrada de tempo?"
                                            />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <flux:separator />
                    @endif

                    {{-- Git Section --}}
                    <div class="space-y-3">
                        <flux:heading size="sm" class="flex items-center gap-2">
                            <flux:icon name="code-bracket" variant="mini" class="text-green-400" />
                            Git
                        </flux:heading>

                        {{-- PR URL --}}
                        @if ($prUrl && !$editingPrUrl)
                            <div class="flex items-center gap-2">
                                <flux:label class="mb-0">Pull Request</flux:label>
                                <a
                                    href="{{ $prUrl }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-2 rounded-lg border border-orange-800/50 bg-orange-950/20 px-3 py-1.5 text-sm text-orange-400 transition hover:border-orange-700 hover:bg-orange-950/40"
                                >
                                    <flux:icon name="arrow-top-right-on-square" variant="mini" />
                                    {{ $this->prDisplayLabel }}
                                </a>
                                <flux:button
                                    wire:click="$set('editingPrUrl', true)"
                                    variant="ghost"
                                    size="xs"
                                    icon="pencil"
                                />
                            </div>
                        @else
                            <div class="flex items-center gap-2">
                                <flux:input
                                    wire:model="prUrl"
                                    label="PR URL"
                                    placeholder="https://github.com/user/repo/pull/123"
                                    size="sm"
                                    class="flex-1"
                                />
                                @if ($prUrl)
                                    <flux:button
                                        wire:click="$set('editingPrUrl', false)"
                                        variant="ghost"
                                        size="xs"
                                        icon="check"
                                        class="mt-5"
                                    />
                                @endif
                            </div>
                        @endif

                        {{-- Commits List --}}
                        @if (count($commits) > 0)
                            <div class="space-y-2">
                                @foreach ($commits as $commit)
                                    <div class="flex items-center gap-3 rounded-lg border border-zinc-700 bg-zinc-800/50 px-3 py-2">
                                        <code class="shrink-0 rounded bg-zinc-700 px-1.5 py-0.5 text-xs font-mono text-zinc-300">
                                            {{ $commit['short_hash'] }}
                                        </code>
                                        <span class="flex-1 truncate text-sm text-zinc-300" title="{{ $commit['message'] }}">
                                            {{ Str::limit($commit['message'], 50) }}
                                        </span>
                                        <div class="flex shrink-0 items-center gap-2 text-xs text-zinc-500">
                                            <span>{{ $commit['files_changed'] }} {{ $commit['files_changed'] === 1 ? 'arquivo' : 'arquivos' }}</span>
                                            <span class="text-emerald-400">+{{ $commit['insertions'] }}</span>
                                            <span class="text-red-400">-{{ $commit['deletions'] }}</span>
                                            <span>{{ $commit['committed_at'] }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <flux:text class="text-sm text-zinc-500">Nenhum commit registrado</flux:text>
                        @endif
                    </div>

                    {{-- Status Time Section --}}
                    @if (count($statusTimeSegments) > 0)
                        <flux:separator />

                        <div class="space-y-3">
                            <flux:heading size="sm" class="flex items-center gap-2">
                                <flux:icon name="chart-bar" variant="mini" class="text-amber-400" />
                                Tempo por Status
                            </flux:heading>

                            {{-- Segmented Bar --}}
                            <div class="flex h-6 w-full overflow-hidden rounded-lg">
                                @foreach ($statusTimeSegments as $statusValue => $segment)
                                    <flux:tooltip :content="$segment['label'] . ': ' . static::formatDuration($segment['minutes'])" position="top">
                                        <div
                                            class="flex h-full items-center justify-center text-[10px] font-medium text-white transition-all"
                                            style="width: {{ $segment['percentage'] }}%; background-color: {{ $segment['hex_color'] }}; min-width: {{ $segment['percentage'] >= 5 ? '0' : '12' }}px;"
                                        >
                                            @if ($segment['percentage'] >= 10)
                                                {{ round($segment['percentage']) }}%
                                            @endif
                                        </div>
                                    </flux:tooltip>
                                @endforeach
                            </div>

                            {{-- Legend --}}
                            <div class="flex flex-wrap gap-3">
                                @foreach ($statusTimeSegments as $statusValue => $segment)
                                    <div class="flex items-center gap-1.5">
                                        <div class="size-2.5 rounded-full" style="background-color: {{ $segment['hex_color'] }};"></div>
                                        <flux:text class="text-xs">
                                            {{ $segment['label'] }}: {{ static::formatDuration($segment['minutes']) }}
                                        </flux:text>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </flux:tab.panel>
            </flux:tab.group>

            {{-- Zona de Perigo --}}
            <div class="space-y-3">
                <flux:separator />
                <div class="rounded-lg border border-red-900/50 bg-red-950/20 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:heading size="sm" class="text-red-400">Zona de Perigo</flux:heading>
                            <flux:text class="mt-1 text-sm text-zinc-500">
                                Esta ação é irreversível e removerá a task e todas as entradas de tempo.
                            </flux:text>
                        </div>
                        <flux:button
                            wire:click="confirmDelete"
                            variant="danger"
                            size="sm"
                            icon="trash"
                        >
                            Excluir Task
                        </flux:button>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="flex justify-end border-t border-zinc-700 pt-4">
                <flux:button
                    wire:click="saveTask"
                    variant="primary"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="saveTask">Salvar</span>
                    <span wire:loading wire:target="saveTask">Salvando...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model.self="showDeleteConfirm" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Confirmar exclusão</flux:heading>
                <flux:text class="mt-2">
                    Tem certeza que deseja excluir a task <strong>{{ $title }}</strong>? Esta ação não pode ser desfeita.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showDeleteConfirm', false)" variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button wire:click="deleteTask" variant="danger">
                    Sim, excluir
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
