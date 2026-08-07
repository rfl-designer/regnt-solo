<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\ActivityCommit;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
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

    public string $serviceClass = 'standard';

    public string $status = 'inbox';

    /**
     * The status the task had when the modal was opened (the true
     * Eloquent "original" this edit will be diffed against on save). Used
     * to tell a genuine entry into a wait (or a move between two wait
     * categories) apart from re-selecting the status the task already had
     * — mirrors ActivityObserver::handleWaitingState()'s isDirty check.
     */
    public ?string $originalStatus = null;

    public string $waitingFor = '';

    /**
     * Whether the user has explicitly edited "esperando quem" during this
     * modal session. While false, changing project/client re-resolves it
     * from the new effective client instead of keeping a stale name.
     */
    public bool $waitingForTouched = false;

    public int $waitingDays = 0;

    public bool $showWaitingForPrompt = false;

    public string $emergencyReason = '';

    /**
     * Whether the blocking "por que isso é uma Emergência?" field is being
     * shown. The Task Modal collects the motivo inline rather than stacking
     * the shared emergency modal on top of an already-open modal.
     */
    public bool $showEmergencyReasonPrompt = false;

    /**
     * The Emergência already holding the board's single slot, when this
     * save collided with it — renders the blocking "Manter a atual /
     * Substituir" choice.
     *
     * @var array{id: int, title: string, reason: string|null, age_in_days: int}|null
     */
    public ?array $emergencyConflict = null;

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
     * The direct client select is only meaningful when the task has no
     * project. Reassigning the project while sitting in a client-side wait
     * re-resolves "esperando quem" from the new effective client, unless
     * the user has explicitly edited the field this session.
     */
    public function updatedProjectId(): void
    {
        if ($this->projectId) {
            $this->clientId = null;
        }

        $this->resyncWaitingForFromEffectiveClient();
    }

    /**
     * Reassigning the direct client while sitting in a client-side wait
     * re-resolves "esperando quem" the same way updatedProjectId() does.
     */
    public function updatedClientId(): void
    {
        $this->resyncWaitingForFromEffectiveClient();
    }

    /**
     * Marks "esperando quem" as explicitly touched so a subsequent
     * project/client change doesn't silently overwrite what the user just
     * typed.
     */
    public function updatedWaitingFor(): void
    {
        $this->waitingForTouched = true;
    }

    /**
     * Mirror the domain guard's auto-fill/prompt behavior in the UI as the
     * user picks a status, so the "esperando quem" requirement is visible
     * before they hit save rather than only surfacing as a refusal.
     *
     * A move between two *different* waiting categories — e.g.
     * `awaiting_approval` -> `waiting`, or `waiting` -> `awaiting_validation`
     * — is treated as a fresh entry: any value inherited from the previous
     * wait is discarded rather than silently reused, mirroring
     * ActivityObserver::handleWaitingState(). Re-selecting the status the
     * task already had (comparing against $originalStatus, the true
     * Eloquent original) is not a new entry and leaves the field alone.
     */
    public function updatedStatus(string $value): void
    {
        $newStatus = ActivityStatus::tryFrom($value);

        if ($newStatus === null || ! $newStatus->isWaiting()) {
            $this->showWaitingForPrompt = false;

            return;
        }

        $originalStatus = ActivityStatus::tryFrom((string) $this->originalStatus);
        $enteringWaitThisEdit = $originalStatus === null || $originalStatus !== $newStatus;

        if ($enteringWaitThisEdit) {
            $this->waitingForTouched = false;

            if ($newStatus->isClientWaiting()) {
                $this->waitingFor = $this->resolveEffectiveClientName() ?? '';
            } else {
                // Internal wait: never inherit a value from a previous wait category.
                $this->waitingFor = '';
            }
        }

        // The internal wait (Esperando) has no client to fall back on, so a
        // blank "esperando quem" here means the mini modal must block save.
        $this->showWaitingForPrompt = $newStatus->isInternalWaiting() && trim($this->waitingFor) === '';
    }

    /**
     * Re-resolve "esperando quem" from the currently selected project/
     * client, but only while the task is sitting in a client-side wait and
     * the user hasn't explicitly typed their own value this session.
     */
    private function resyncWaitingForFromEffectiveClient(): void
    {
        $status = ActivityStatus::tryFrom($this->status);

        if ($status === null || ! $status->isClientWaiting() || $this->waitingForTouched) {
            return;
        }

        $this->waitingFor = $this->resolveEffectiveClientName() ?? '';
    }

    /**
     * Resolve the name of the effective client for the currently selected
     * project (or direct client when there is no project).
     */
    private function resolveEffectiveClientName(): ?string
    {
        $client = $this->projectId
            ? Project::find((int) $this->projectId)?->client
            : ($this->clientId ? Client::find((int) $this->clientId) : null);

        return $client?->name;
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
        $this->serviceClass = $task->service_class->value;
        $this->status = $task->status->value;
        $this->originalStatus = $task->status->value;
        $this->waitingFor = $task->waiting_for ?? '';
        $this->waitingForTouched = false;
        $this->waitingDays = $task->waitingDays();
        $this->showWaitingForPrompt = false;
        $this->emergencyReason = $task->emergency_reason ?? '';
        $this->showEmergencyReasonPrompt = false;
        $this->emergencyConflict = null;
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
            'serviceClass' => 'required|in:'.implode(',', array_column(ServiceClass::cases(), 'value')),
            'status' => 'required|in:'.implode(',', array_column(ActivityStatus::cases(), 'value')),
            'dueDate' => 'nullable|date',
            'estimatedMinutes' => 'nullable|integer|min:1',
        ]);

        $task = Activity::findOrFail($this->taskId);

        $newStatus = ActivityStatus::from($this->status);

        // The internal wait (Esperando) has no client to fall back on: block
        // the save and surface the mini modal instead of hitting the domain
        // guard's refusal.
        if ($newStatus->isInternalWaiting() && trim($this->waitingFor) === '') {
            $this->showWaitingForPrompt = true;

            return;
        }

        $this->showWaitingForPrompt = false;

        // An Emergência always needs a motivo. Block the save and reveal the
        // field instead of letting the domain guard refuse a save the user
        // can't fix from here.
        if ($this->serviceClass === ServiceClass::Emergency->value && trim($this->emergencyReason) === '') {
            $this->showEmergencyReasonPrompt = true;

            return;
        }

        $this->showEmergencyReasonPrompt = false;

        try {
            if ($newStatus === ActivityStatus::Done && $task->status !== ActivityStatus::Done) {
                $task->update([
                    'title' => $this->title,
                    'project_id' => $this->projectId ? (int) $this->projectId : null,
                    'client_id' => $this->projectId ? null : ($this->clientId ? (int) $this->clientId : null),
                    'service_class' => $this->serviceClass,
                    'emergency_reason' => $this->emergencyReason ?: null,
                    'waiting_for' => $this->waitingFor ?: null,
                    'due_date' => $this->dueDate ?: null,
                    'estimated_minutes' => $this->estimatedMinutes,
                    'pr_url' => $this->prUrl ?: null,
                    'session_prompt' => $this->sessionPrompt ?: null,
                    'session_result' => $this->sessionResult ?: null,
                ]);
                $task->markAsDone();
                $this->status = ActivityStatus::Done->value;
                $this->originalStatus = ActivityStatus::Done->value;
                $this->waitingFor = '';
                $this->waitingForTouched = false;
                $this->waitingDays = 0;
            } else {
                $task->update([
                    'title' => $this->title,
                    'project_id' => $this->projectId ? (int) $this->projectId : null,
                    'client_id' => $this->projectId ? null : ($this->clientId ? (int) $this->clientId : null),
                    'status' => $this->status,
                    'service_class' => $this->serviceClass,
                    'emergency_reason' => $this->emergencyReason ?: null,
                    'waiting_for' => $this->waitingFor ?: null,
                    'due_date' => $this->dueDate ?: null,
                    'estimated_minutes' => $this->estimatedMinutes,
                    'completed_at' => $newStatus === ActivityStatus::Done ? $task->completed_at : null,
                    'pr_url' => $this->prUrl ?: null,
                    'session_prompt' => $this->sessionPrompt ?: null,
                    'session_result' => $this->sessionResult ?: null,
                ]);

                $task->refresh();
                $this->originalStatus = $task->status->value;
                $this->waitingFor = $task->waiting_for ?? '';
                $this->waitingForTouched = false;
                $this->waitingDays = $task->waitingDays();
            }
        } catch (\App\Exceptions\SingleActiveEmergencyException $e) {
            // Blocking choice, not a toast: the user has to say which of the
            // two is the Emergência before anything is written.
            $this->emergencyConflict = $e->activeEmergencyContext();

            return;
        } catch (\App\Exceptions\WaitingRequiresWaitingForException $e) {
            Flux::toast(variant: 'danger', heading: 'Não foi possível salvar', text: $e->getMessage());

            return;
        } catch (\App\Exceptions\FixedDateRequiresDueDateException $e) {
            Flux::toast(variant: 'danger', heading: 'Não foi possível salvar', text: $e->getMessage());

            return;
        } catch (\App\Exceptions\DoingWipLimitExceededException $e) {
            Flux::toast(variant: 'danger', heading: 'Não foi possível salvar', text: $e->getMessage());

            return;
        }

        $this->emergencyConflict = null;

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

    /**
     * Keep the Emergência that already holds the board's slot: this task
     * falls back to the classification it had, and the save is retried so
     * the user's other edits aren't lost.
     */
    public function keepCurrentEmergency(): void
    {
        $task = Activity::findOrFail($this->taskId);

        $this->serviceClass = $task->service_class->value;
        $this->emergencyReason = $task->emergency_reason ?? '';
        $this->emergencyConflict = null;

        $this->saveTask();
    }

    /**
     * Replace the current Emergência with this task: demote the active one
     * first, then let the normal save promote this one. Chained on purpose
     * — the promotion is only attempted once the demotion is actually
     * saved, and both share a transaction so the board is never left
     * without the Emergência the user meant to have.
     */
    public function replaceEmergency(): void
    {
        $conflictId = $this->emergencyConflict['id'] ?? null;

        if ($conflictId === null) {
            return;
        }

        DB::transaction(function () use ($conflictId): void {
            Activity::findOrFail($conflictId)->update([
                'service_class' => ServiceClass::Standard,
            ]);
        });

        $this->emergencyConflict = null;

        $this->saveTask();
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
        $this->reset('taskId', 'title', 'projectId', 'clientId', 'serviceClass', 'status', 'originalStatus', 'waitingFor', 'waitingForTouched', 'waitingDays', 'showWaitingForPrompt', 'emergencyReason', 'showEmergencyReasonPrompt', 'emergencyConflict', 'dueDate', 'estimatedMinutes', 'timeEntries', 'prUrl', 'editingPrUrl', 'activeTab', 'commits', 'sessionPrompt', 'sessionResult', 'projectDocuments');

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

                        {{-- Service Class --}}
                        <flux:select wire:model="serviceClass" label="Classe de serviço">
                            @foreach (ServiceClass::cases() as $sc)
                                <flux:select.option :value="$sc->value">
                                    {{ $sc->label() }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        @if ($serviceClass === \App\Enums\ServiceClass::FixedDate->value && !$dueDate)
                            <flux:text size="sm" class="text-amber-400">
                                {{ \App\Exceptions\FixedDateRequiresDueDateException::MESSAGE }}
                            </flux:text>
                        @endif

                        {{-- Status --}}
                        <flux:select wire:model.live="status" label="Status">
                            @foreach (ActivityStatus::cases() as $s)
                                <flux:select.option :value="$s->value">
                                    {{ $s->label() }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>

                        {{-- Due Date --}}
                        <flux:date-picker wire:model="dueDate" label="Prazo" clearable />

                        {{-- Waiting For (visible while in any waiting status) --}}
                        @php $selectedStatus = ActivityStatus::tryFrom($status); @endphp
                        @if ($selectedStatus?->isWaiting())
                            <div class="sm:col-span-2">
                                <flux:input
                                    wire:model.live="waitingFor"
                                    label="Esperando quem"
                                    placeholder="Ex: Cliente, Designer, DevOps..."
                                />
                                @if ($waitingDays > 0)
                                    <flux:badge size="sm" color="{{ $selectedStatus->color() }}" icon="{{ $selectedStatus->icon() }}" class="mt-2">
                                        ⏳ {{ $waitingFor }} · há {{ $waitingDays }} {{ $waitingDays === 1 ? 'dia' : 'dias' }}
                                    </flux:badge>
                                @endif
                            </div>
                        @endif

                        {{-- Emergência: motivo obrigatório + conflito com a
                             Emergência que já ocupa a vaga do board. Shown via
                             Alpine off the select's live client-side value, so
                             the mandatory field appears the instant Emergência
                             is picked — without a server round trip on every
                             change of classification. --}}
                        <div x-show="$wire.serviceClass === '{{ \App\Enums\ServiceClass::Emergency->value }}'" x-cloak class="sm:col-span-2">
                            <div class="space-y-3">
                                <flux:textarea
                                    wire:model="emergencyReason"
                                    label="Motivo da Emergência"
                                    placeholder="Ex: produção fora do ar, cliente parado, prazo legal hoje..."
                                    rows="2"
                                />

                                @if ($showEmergencyReasonPrompt)
                                    <flux:text size="sm" class="text-amber-400">
                                        {{ \App\Exceptions\EmergencyRequiresReasonException::MESSAGE }}
                                    </flux:text>
                                @endif

                                @if ($emergencyConflict)
                                    <div class="rounded-lg border border-red-500/20 bg-zinc-800/50 p-3">
                                        <div class="flex items-start gap-2">
                                            <flux:icon name="fire" class="mt-0.5 size-4 shrink-0 text-red-400" />
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-medium text-zinc-200">Já existe uma Emergência ativa</p>
                                                <p class="mt-1 truncate text-sm text-zinc-300">{{ $emergencyConflict['title'] }}</p>
                                                @if ($emergencyConflict['reason'])
                                                    <p class="mt-1 text-xs text-zinc-400">{{ $emergencyConflict['reason'] }}</p>
                                                @endif
                                                <p class="mt-1 text-xs text-zinc-500">
                                                    Emergência há {{ $emergencyConflict['age_in_days'] }} {{ $emergencyConflict['age_in_days'] === 1 ? 'dia' : 'dias' }}
                                                </p>

                                                <div class="mt-3 flex gap-2">
                                                    <flux:button wire:click="keepCurrentEmergency" size="sm" variant="ghost">
                                                        Manter a atual
                                                    </flux:button>
                                                    <flux:button wire:click="replaceEmergency" size="sm" variant="danger">
                                                        Substituir
                                                    </flux:button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
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

    {{-- Waiting For Prompt (blocking mini modal for the internal wait) --}}
    <flux:modal wire:model.self="showWaitingForPrompt" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Quem está sendo aguardado?</flux:heading>
                <flux:text class="mt-2">
                    Mover para "Esperando" exige informar quem está sendo aguardado.
                </flux:text>
            </div>

            <flux:input
                wire:model.live="waitingFor"
                label="Esperando quem"
                placeholder="Ex: Designer, DevOps, Suporte..."
                autofocus
            />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showWaitingForPrompt', false)" variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button wire:click="saveTask" variant="primary">
                    Confirmar e salvar
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
