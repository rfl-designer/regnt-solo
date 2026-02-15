<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $rawInput = '';

    public ?int $selectedProjectId = null;

    public ?string $selectedPriority = null;

    public ?string $selectedDueDate = null;

    public bool $isSessionMode = false;

    public string $sessionPromptInput = '';

    public string $activePrefix = '';

    public string $prefixSearch = '';

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::active()->orderBy('name')->get();
    }

    /**
     * Get filtered suggestions based on the active prefix.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function filteredSuggestions(): array
    {
        $search = mb_strtolower($this->prefixSearch);

        return match ($this->activePrefix) {
            '#' => $this->projects
                ->filter(fn (Project $project) => $search === '' || str_contains(mb_strtolower($project->slug), $search))
                ->map(fn (Project $project) => [
                    'value' => $project->slug,
                    'label' => "{$project->emoji} {$project->name}",
                ])
                ->values()
                ->all(),

            '!' => collect(TaskPriority::cases())
                ->filter(fn (TaskPriority $priority) => $search === '' || str_contains($priority->value, $search))
                ->map(fn (TaskPriority $priority) => [
                    'value' => $priority->value,
                    'label' => $priority->label(),
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

    public function updatedRawInput(): void
    {
        $this->detectActivePrefix();
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
        $priority = null;
        $dateAlias = null;

        if (preg_match('/#(\S+)/', $input, $matches)) {
            $projectSlug = $matches[1];
        }

        if (preg_match('/!(\S+)/', $input, $matches)) {
            $priority = $matches[1];
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

        $taskPriority = null;
        if ($priority !== null) {
            $taskPriority = TaskPriority::tryFrom($priority);
        }

        $dueDate = $this->parseDateAlias($dateAlias);

        Task::create([
            'title' => $title,
            'project_id' => $projectId,
            'status' => TaskStatus::Inbox,
            'priority' => $taskPriority ?? TaskPriority::Medium,
            'due_date' => $dueDate,
            'session_prompt' => $sessionPrompt,
        ]);

        Flux::toast(variant: 'success', heading: 'Task criada', text: $title);

        $this->dispatch('task-created');

        $this->reset('rawInput', 'selectedProjectId', 'selectedPriority', 'selectedDueDate', 'activePrefix', 'prefixSearch', 'isSessionMode', 'sessionPromptInput');

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
    <flux:modal.trigger name="quick-add" shortcut="n">
        <flux:button icon="plus" variant="ghost" size="sm" class="sr-only">
            Nova Task
        </flux:button>
    </flux:modal.trigger>

    {{-- Modal --}}
    <flux:modal name="quick-add" class="md:w-96">
        <div class="space-y-4" x-data="{
            suggestions: @js($this->filteredSuggestions),
            activePrefix: @entangle('activePrefix'),
            highlightIndex: 0,
            get showSuggestions() {
                return this.activePrefix !== '' && this.suggestions.length > 0;
            }
        }"
        x-on:keydown.arrow-down.prevent="highlightIndex = Math.min(highlightIndex + 1, suggestions.length - 1)"
        x-on:keydown.arrow-up.prevent="highlightIndex = Math.max(highlightIndex - 1, 0)"
        x-on:keydown.enter.prevent="
            if (showSuggestions) {
                $wire.selectSuggestion(suggestions[highlightIndex].value);
                highlightIndex = 0;
            } else {
                $wire.createTask();
            }
        "
        >
            <div>
                <flux:heading size="lg">Nova Task</flux:heading>
                <flux:text class="mt-1">
                    Use <code class="rounded bg-zinc-700 px-1 py-0.5 text-xs">#projeto</code>
                    <code class="rounded bg-zinc-700 px-1 py-0.5 text-xs">!prioridade</code>
                    <code class="rounded bg-zinc-700 px-1 py-0.5 text-xs">@data</code>
                </flux:text>
            </div>

            <div class="relative">
                <flux:input
                    wire:model.live.debounce.150ms="rawInput"
                    placeholder="Ex: Revisar PR #meu-projeto !high @hoje"
                    autofocus
                    x-on:keydown.escape.prevent="$flux.modal('quick-add').close()"
                />

                {{-- Autocomplete dropdown --}}
                <div
                    x-show="showSuggestions"
                    x-cloak
                    x-effect="suggestions = @js($this->filteredSuggestions); highlightIndex = 0;"
                    class="absolute z-50 mt-1 w-full overflow-hidden rounded-lg border border-zinc-700 bg-zinc-800 shadow-lg"
                >
                    <ul class="max-h-48 overflow-y-auto py-1">
                        @foreach ($this->filteredSuggestions as $index => $suggestion)
                            <li
                                wire:key="suggestion-{{ $index }}"
                                x-on:click="$wire.selectSuggestion('{{ $suggestion['value'] }}'); highlightIndex = 0;"
                                x-bind:class="highlightIndex === {{ $index }} ? 'bg-zinc-700' : ''"
                                class="cursor-pointer px-3 py-2 text-sm hover:bg-zinc-700"
                            >
                                {{ $suggestion['label'] }}
                            </li>
                        @endforeach
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
