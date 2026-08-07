<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public string $rawInput = '';

    public ?int $selectedProjectId = null;

    public ?string $selectedServiceClass = null;

    public ?string $selectedDueDate = null;

    public bool $isSessionMode = false;

    public string $sessionPromptInput = '';

    public string $activePrefix = '';

    public string $prefixSearch = '';

    /** @var array<int, array{value: string, label: string}> */
    public array $suggestions = [];

    public ?string $initialStatus = null;

    #[On('open-quick-add-with-status')]
    public function openWithStatus(string $status): void
    {
        $this->initialStatus = $status;
        Flux::modal('quick-add')->show();
    }

    #[On('open-quick-add-with-project')]
    public function openWithProject(int $projectId): void
    {
        $project = Project::find($projectId);

        if ($project) {
            $this->rawInput = '#'.$project->slug.' ';
        }

        Flux::modal('quick-add')->show();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::active()->orderBy('name')->get();
    }

    public function updatedRawInput(): void
    {
        $this->detectActivePrefix();
        $this->updateSuggestions();
    }

    /**
     * Update the suggestions array based on the active prefix.
     */
    private function updateSuggestions(): void
    {
        $search = mb_strtolower($this->prefixSearch);

        $this->suggestions = match ($this->activePrefix) {
            '#' => $this->projects
                ->filter(fn (Project $project) => $search === '' || str_contains(mb_strtolower($project->slug), $search))
                ->map(fn (Project $project) => [
                    'value' => $project->slug,
                    'label' => "{$project->emoji} {$project->name}",
                ])
                ->values()
                ->all(),

            '!' => collect(ServiceClass::cases())
                ->filter(fn (ServiceClass $serviceClass) => $search === '' || str_contains($serviceClass->value, $search))
                ->map(fn (ServiceClass $serviceClass) => [
                    'value' => $serviceClass->value,
                    'label' => $serviceClass->label(),
                ])
                ->values()
                ->all(),

            '@' => collect($this->dateOptions())
                ->filter(fn (array $option) => $search === '' || str_contains($option['value'], $search))
                ->values()
                ->all(),

            default => [],
        };
    }

    public function selectSuggestion(string $value): void
    {
        $prefix = $this->activePrefix;

        if ($prefix === '') {
            return;
        }

        $pattern = '/'.preg_quote($prefix, '/').'(\S*)$/';
        $this->rawInput = preg_replace($pattern, $prefix.$value.' ', $this->rawInput);

        $this->activePrefix = '';
        $this->prefixSearch = '';
    }

    public function createTask(): void
    {
        $input = trim($this->rawInput);

        if ($input === '') {
            return;
        }

        $projectSlug = null;
        $serviceClass = null;
        $dateAlias = null;

        if (preg_match('/#(\S+)/', $input, $matches)) {
            $projectSlug = $matches[1];
        }

        if (preg_match('/!(\S+)/', $input, $matches)) {
            $serviceClass = $matches[1];
        }

        if (preg_match('/@(\S+)/', $input, $matches)) {
            $dateAlias = $matches[1];
        }

        $title = trim(preg_replace('/[#!@]\S+/', '', $input));

        // Detectar session prompt (prefixo >)
        $sessionPrompt = null;
        if (str_starts_with($title, '>')) {
            $sessionPrompt = trim(substr($title, 1));
            $title = Str::limit($sessionPrompt, 80);
        }

        // Ou via checkbox mode
        if ($this->isSessionMode && $this->sessionPromptInput) {
            $sessionPrompt = $this->sessionPromptInput;
        }

        if ($title === '') {
            return;
        }

        $projectId = null;
        if ($projectSlug !== null) {
            $project = Project::where('slug', $projectSlug)->first();
            if ($project) {
                $projectId = $project->id;
            } else {
                Flux::toast(variant: 'warning', heading: 'Projeto não encontrado', text: "Projeto #{$projectSlug} não encontrado. Task criada sem projeto.");
            }
        }

        $taskServiceClass = null;
        $invalidServiceClass = false;
        if ($serviceClass !== null) {
            $taskServiceClass = ServiceClass::tryFrom($serviceClass);
            $invalidServiceClass = $taskServiceClass === null;
        }

        $dueDate = $this->parseDateAlias($dateAlias);

        $taskStatus = $this->initialStatus !== null
            ? ActivityStatus::tryFrom($this->initialStatus) ?? ActivityStatus::Inbox
            : ActivityStatus::Inbox;

        // Quick-Add always creates in Inbox (issue #142 criterion). The "+"
        // button on a waiting column (Aguardando aprovação/validação,
        // Esperando) would otherwise create straight into a status that
        // requires "esperando quem", which Quick-Add has no field for —
        // fall back to Inbox instead of surfacing the domain guard as a
        // raw Livewire error.
        if ($taskStatus->isWaiting()) {
            Flux::toast(variant: 'warning', heading: 'Criada na Inbox', text: 'Quick-Add sempre cria na Inbox; mover para espera exige informar "esperando quem" no Kanban ou no Task Modal.');
            $taskStatus = ActivityStatus::Inbox;
        }

        if ($invalidServiceClass) {
            Flux::toast(variant: 'warning', heading: 'Classe de serviço inválida', text: "\"!{$serviceClass}\" não é uma classe válida. Task criada como Padrão.");
        }

        // "!emergency" can't be applied inline: an Emergência needs a motivo
        // and may collide with the one already on the board. Quick-Add has
        // no field for either, so the task is created as Padrão and the
        // blocking Emergência modal takes over from there — same checkpoint
        // the Inbox and the Command Palette defer to.
        $wantsEmergency = $taskServiceClass === ServiceClass::Emergency;

        try {
            $task = Activity::create([
                'type' => ActivityType::Task,
                'title' => $title,
                'project_id' => $projectId,
                'status' => $taskStatus,
                'service_class' => $wantsEmergency ? ServiceClass::Standard : ($taskServiceClass ?? ServiceClass::Standard),
                'due_date' => $dueDate,
                'session_prompt' => $sessionPrompt,
            ]);
        } catch (\App\Exceptions\FixedDateRequiresDueDateException $e) {
            Flux::toast(variant: 'danger', heading: 'Não foi possível criar a task', text: $e->getMessage());

            return;
        }

        $statusLabel = $taskStatus->label();
        Flux::toast(variant: 'success', heading: 'Task criada', text: "{$title} → {$statusLabel}");

        $this->dispatch('task-created');

        if ($wantsEmergency) {
            $this->dispatch('open-emergency-modal', taskId: $task->id);
        }

        $this->reset('rawInput', 'selectedProjectId', 'selectedServiceClass', 'selectedDueDate', 'activePrefix', 'prefixSearch', 'isSessionMode', 'sessionPromptInput', 'initialStatus');

        Flux::modal('quick-add')->close();
    }

    /**
     * Detect the active prefix being typed.
     */
    private function detectActivePrefix(): void
    {
        $cursorText = $this->rawInput;

        if (preg_match('/([#!@])(\S*)$/', $cursorText, $matches)) {
            $this->activePrefix = $matches[1];
            $this->prefixSearch = $matches[2];
        } else {
            $this->activePrefix = '';
            $this->prefixSearch = '';
        }
    }

    /**
     * Parse a date alias to a Carbon instance.
     */
    private function parseDateAlias(?string $alias): ?Carbon
    {
        if ($alias === null) {
            return null;
        }

        return match ($alias) {
            'hoje' => Carbon::today(),
            'amanha' => Carbon::tomorrow(),
            'segunda' => Carbon::parse('next monday'),
            'proxima-semana' => Carbon::now()->addWeek()->startOfWeek(),
            default => null,
        };
    }

    /**
     * Get available date options for autocomplete.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function dateOptions(): array
    {
        return [
            ['value' => 'hoje', 'label' => 'Hoje'],
            ['value' => 'amanha', 'label' => 'Amanhã'],
            ['value' => 'segunda', 'label' => 'Próxima segunda'],
            ['value' => 'proxima-semana', 'label' => 'Próxima semana'],
        ];
    }
};

?>

<div>
    {{-- Modal trigger with keyboard shortcut --}}
    <flux:modal.trigger name="quick-add" shortcut="ctrl.n">
        <flux:button icon="plus" variant="ghost" size="sm" class="sr-only">
            Nova Task
        </flux:button>
    </flux:modal.trigger>

    {{-- Modal --}}
    <flux:modal name="quick-add" class="md:w-[32rem]">
        <div class="space-y-4" x-data="{
            suggestions: @entangle('suggestions'),
            activePrefix: @entangle('activePrefix'),
            highlightIndex: 0,
            get showSuggestions() {
                return this.activePrefix !== '' && this.suggestions.length > 0;
            }
        }"
        x-on:keydown.arrow-down.prevent="highlightIndex = Math.min(highlightIndex + 1, suggestions.length - 1)"
        x-on:keydown.arrow-up.prevent="highlightIndex = Math.max(highlightIndex - 1, 0)"
        x-on:keydown.enter.prevent="
            if (showSuggestions && suggestions[highlightIndex]) {
                $wire.selectSuggestion(suggestions[highlightIndex].value);
                highlightIndex = 0;
            } else if (!showSuggestions) {
                $wire.createTask();
            }
        "
        >
            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">Nova Task</flux:heading>
                    @if ($initialStatus)
                        @php $statusEnum = \App\Enums\ActivityStatus::tryFrom($initialStatus); @endphp
                        @if ($statusEnum)
                            <flux:badge size="sm" color="{{ $statusEnum->color() }}" icon="{{ $statusEnum->icon() }}">
                                → {{ $statusEnum->label() }}
                            </flux:badge>
                        @endif
                    @endif
                </div>
                <flux:text class="mt-1">
                    Use <code class="rounded bg-zinc-700 px-1 py-0.5 text-xs">#projeto</code>
                    <code class="rounded bg-zinc-700 px-1 py-0.5 text-xs">!classe</code>
                    <code class="rounded bg-zinc-700 px-1 py-0.5 text-xs">@data</code>
                </flux:text>
            </div>

            <div class="relative">
                <flux:input
                    wire:model.live.debounce.150ms="rawInput"
                    placeholder="Ex: Revisar PR #meu-projeto !high @hoje"
                    autofocus
                    x-on:input="highlightIndex = 0"
                    x-on:keydown.escape.prevent="$flux.modal('quick-add').close()"
                />

                {{-- Autocomplete dropdown --}}
                <div
                    x-show="showSuggestions"
                    x-cloak
                    class="absolute z-50 mt-1 w-full overflow-hidden rounded-lg border border-zinc-700 bg-zinc-800 shadow-lg"
                >
                    <ul class="max-h-48 overflow-y-auto py-1">
                        <template x-for="(suggestion, index) in suggestions" :key="index">
                            <li
                                x-on:click="$wire.selectSuggestion(suggestion.value); highlightIndex = 0;"
                                x-bind:class="highlightIndex === index ? 'bg-zinc-700' : ''"
                                class="cursor-pointer px-3 py-2 text-sm hover:bg-zinc-700"
                                x-text="suggestion.label"
                            ></li>
                        </template>
                    </ul>
                </div>
            </div>

            {{-- Parsed tokens preview --}}
            @if ($rawInput !== '')
                <div class="flex flex-wrap gap-2">
                    @if (preg_match('/#(\S+)/', $rawInput, $m))
                        <flux:badge size="sm" color="blue" icon="folder">{{ $m[1] }}</flux:badge>
                    @endif
                    @if (preg_match('/!(\S+)/', $rawInput, $m))
                        <flux:badge size="sm" color="amber" icon="flag">{{ $m[1] }}</flux:badge>
                    @endif
                    @if (preg_match('/@(\S+)/', $rawInput, $m))
                        <flux:badge size="sm" color="emerald" icon="calendar-days">{{ $m[1] }}</flux:badge>
                    @endif
                </div>
            @endif

            {{-- Sessão de Dev --}}
            <div class="flex items-center gap-2">
                <flux:checkbox wire:model.live="isSessionMode" />
                <flux:text size="sm" class="text-zinc-400">Sessão de Dev</flux:text>
            </div>

            @if ($isSessionMode)
                <flux:textarea
                    wire:model="sessionPromptInput"
                    rows="3"
                    placeholder="Descreva o que o AI deve implementar..."
                />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="createTask" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="createTask">Criar Task</span>
                    <span wire:loading wire:target="createTask">Criando...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
