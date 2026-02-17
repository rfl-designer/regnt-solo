<?php

use App\Enums\RecurrenceFrequency;
use App\Enums\TaskPriority;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\TaskTemplate;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $tab = 'templates';

    // Template form
    public bool $showTemplateForm = false;
    public ?int $editingTemplateId = null;
    public string $templateName = '';
    public string $templateDescription = '';
    public string $templatePriority = 'medium';
    public ?int $templateEstimatedMinutes = null;
    public string $templateIcon = '';
    public string $templateColor = '';

    // Recurring task form
    public bool $showRecurringForm = false;
    public ?int $editingRecurringId = null;
    public string $recurringTitle = '';
    public string $recurringDescription = '';
    public string $recurringFrequency = 'weekly';
    public ?int $recurringDayOfWeek = 1;
    public ?int $recurringDayOfMonth = 1;
    public string $recurringPriority = 'medium';
    public ?int $recurringProjectId = null;
    public ?int $recurringEstimatedMinutes = null;

    // Delete modals
    public bool $showDeleteTemplateModal = false;
    public ?int $deletingTemplateId = null;
    public ?string $deletingTemplateName = null;

    public bool $showDeleteRecurringModal = false;
    public ?int $deletingRecurringId = null;
    public ?string $deletingRecurringTitle = null;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TaskTemplate>
     */
    #[Computed]
    public function templates(): \Illuminate\Database\Eloquent\Collection
    {
        return TaskTemplate::query()
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, RecurringTask>
     */
    #[Computed]
    public function recurringTasks(): \Illuminate\Database\Eloquent\Collection
    {
        return RecurringTask::query()
            ->with('project')
            ->orderByDesc('is_active')
            ->orderBy('next_run')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::active()->orderBy('name')->get();
    }

    public function openTemplateForm(?int $templateId = null): void
    {
        if ($templateId) {
            $template = TaskTemplate::findOrFail($templateId);
            $this->editingTemplateId = $template->id;
            $this->templateName = $template->name;
            $this->templateDescription = $template->description ?? '';
            $this->templatePriority = $template->default_priority->value;
            $this->templateEstimatedMinutes = $template->default_estimated_minutes;
            $this->templateIcon = $template->icon ?? '';
            $this->templateColor = $template->color ?? '';
        } else {
            $this->resetTemplateForm();
        }

        $this->showTemplateForm = true;
    }

    public function saveTemplate(): void
    {
        $this->validate([
            'templateName' => 'required|string|max:255',
            'templateDescription' => 'nullable|string',
            'templatePriority' => 'required|in:urgent,high,medium,low',
            'templateEstimatedMinutes' => 'nullable|integer|min:1',
        ]);

        $data = [
            'name' => $this->templateName,
            'slug' => Str::slug($this->templateName),
            'description' => $this->templateDescription ?: null,
            'default_priority' => $this->templatePriority,
            'default_estimated_minutes' => $this->templateEstimatedMinutes,
            'icon' => $this->templateIcon ?: null,
            'color' => $this->templateColor ?: null,
        ];

        if ($this->editingTemplateId) {
            $template = TaskTemplate::findOrFail($this->editingTemplateId);
            $template->update($data);
            $message = 'Template atualizado';
        } else {
            TaskTemplate::create($data);
            $message = 'Template criado';
        }

        $this->resetTemplateForm();
        $this->showTemplateForm = false;
        unset($this->templates);

        Flux::toast(text: $message, variant: 'success', heading: 'Sucesso');
    }

    public function confirmDeleteTemplate(int $templateId): void
    {
        $template = TaskTemplate::findOrFail($templateId);
        $this->deletingTemplateId = $template->id;
        $this->deletingTemplateName = $template->name;
        $this->showDeleteTemplateModal = true;
    }

    public function deleteTemplate(): void
    {
        if ($this->deletingTemplateId === null) {
            return;
        }

        $template = TaskTemplate::findOrFail($this->deletingTemplateId);

        if ($template->is_system) {
            Flux::toast(text: 'Templates de sistema não podem ser excluídos.', variant: 'danger', heading: 'Erro');
            $this->reset('deletingTemplateId', 'deletingTemplateName', 'showDeleteTemplateModal');

            return;
        }

        $template->delete();

        $this->reset('deletingTemplateId', 'deletingTemplateName', 'showDeleteTemplateModal');
        unset($this->templates);

        Flux::toast(text: 'Template removido', variant: 'success', heading: 'Template excluído');
    }

    public function useTemplate(int $templateId): void
    {
        $template = TaskTemplate::findOrFail($templateId);
        $task = $template->createTask();

        unset($this->templates);

        $this->dispatch('task-created');

        Flux::toast(variant: 'success', heading: 'Task criada', text: $task->title);
    }

    public function openRecurringForm(?int $recurringId = null): void
    {
        if ($recurringId) {
            $recurring = RecurringTask::findOrFail($recurringId);
            $this->editingRecurringId = $recurring->id;
            $this->recurringTitle = $recurring->title;
            $this->recurringDescription = $recurring->description ?? '';
            $this->recurringFrequency = $recurring->frequency->value;
            $this->recurringDayOfWeek = $recurring->day_of_week;
            $this->recurringDayOfMonth = $recurring->day_of_month;
            $this->recurringPriority = $recurring->priority->value;
            $this->recurringProjectId = $recurring->project_id;
            $this->recurringEstimatedMinutes = $recurring->estimated_minutes;
        } else {
            $this->resetRecurringForm();
        }

        $this->showRecurringForm = true;
    }

    public function saveRecurring(): void
    {
        $this->validate([
            'recurringTitle' => 'required|string|max:255',
            'recurringDescription' => 'nullable|string',
            'recurringFrequency' => 'required|in:daily,weekdays,weekly,biweekly,monthly',
            'recurringDayOfWeek' => 'nullable|integer|min:0|max:6',
            'recurringDayOfMonth' => 'nullable|integer|min:1|max:31',
            'recurringPriority' => 'required|in:urgent,high,medium,low',
            'recurringProjectId' => 'nullable|exists:projects,id',
            'recurringEstimatedMinutes' => 'nullable|integer|min:1',
        ]);

        $frequency = RecurrenceFrequency::from($this->recurringFrequency);

        $data = [
            'title' => $this->recurringTitle,
            'description' => $this->recurringDescription ?: null,
            'frequency' => $frequency,
            'day_of_week' => $frequency === RecurrenceFrequency::Weekly ? $this->recurringDayOfWeek : null,
            'day_of_month' => $frequency === RecurrenceFrequency::Monthly ? $this->recurringDayOfMonth : null,
            'priority' => $this->recurringPriority,
            'project_id' => $this->recurringProjectId,
            'estimated_minutes' => $this->recurringEstimatedMinutes,
        ];

        if ($this->editingRecurringId) {
            $recurring = RecurringTask::findOrFail($this->editingRecurringId);
            $recurring->update($data);
            $message = 'Task recorrente atualizada';
        } else {
            $data['next_run'] = $this->calculateInitialNextRun($frequency);
            RecurringTask::create($data);
            $message = 'Task recorrente criada';
        }

        $this->resetRecurringForm();
        $this->showRecurringForm = false;
        unset($this->recurringTasks);

        Flux::toast(text: $message, variant: 'success', heading: 'Sucesso');
    }

    public function toggleRecurringActive(int $recurringId): void
    {
        $recurring = RecurringTask::findOrFail($recurringId);
        $recurring->update(['is_active' => ! $recurring->is_active]);

        unset($this->recurringTasks);

        $status = $recurring->is_active ? 'ativada' : 'pausada';
        Flux::toast(text: "Task recorrente foi {$status}", variant: 'success', heading: 'Status alterado');
    }

    public function confirmDeleteRecurring(int $recurringId): void
    {
        $recurring = RecurringTask::findOrFail($recurringId);
        $this->deletingRecurringId = $recurring->id;
        $this->deletingRecurringTitle = $recurring->title;
        $this->showDeleteRecurringModal = true;
    }

    public function deleteRecurring(): void
    {
        if ($this->deletingRecurringId === null) {
            return;
        }

        RecurringTask::findOrFail($this->deletingRecurringId)->delete();

        $this->reset('deletingRecurringId', 'deletingRecurringTitle', 'showDeleteRecurringModal');
        unset($this->recurringTasks);

        Flux::toast(text: 'Task removida', variant: 'success', heading: 'Task recorrente excluída');
    }

    public function runRecurringNow(int $recurringId): void
    {
        $recurring = RecurringTask::findOrFail($recurringId);
        $task = $recurring->process();

        unset($this->recurringTasks);

        $this->dispatch('task-created');

        Flux::toast(variant: 'success', heading: 'Task criada', text: $task->title);
    }

    private function resetTemplateForm(): void
    {
        $this->editingTemplateId = null;
        $this->templateName = '';
        $this->templateDescription = '';
        $this->templatePriority = 'medium';
        $this->templateEstimatedMinutes = null;
        $this->templateIcon = '';
        $this->templateColor = '';
    }

    private function resetRecurringForm(): void
    {
        $this->editingRecurringId = null;
        $this->recurringTitle = '';
        $this->recurringDescription = '';
        $this->recurringFrequency = 'weekly';
        $this->recurringDayOfWeek = 1;
        $this->recurringDayOfMonth = 1;
        $this->recurringPriority = 'medium';
        $this->recurringProjectId = null;
        $this->recurringEstimatedMinutes = null;
    }

    private function calculateInitialNextRun(RecurrenceFrequency $frequency): \Carbon\CarbonInterface
    {
        $today = \Carbon\Carbon::today();

        return match ($frequency) {
            RecurrenceFrequency::Daily => $today->addDay(),
            RecurrenceFrequency::Weekdays => $today->isWeekend() ? $today->nextWeekday() : ($today->copy()->addDay()->isWeekend() ? $today->nextWeekday() : $today->addDay()),
            RecurrenceFrequency::Weekly => $today->addWeek()->setDaysFromStartOfWeek($this->recurringDayOfWeek ?? 1),
            RecurrenceFrequency::Biweekly => $today->addWeeks(2),
            RecurrenceFrequency::Monthly => $today->addMonth()->setDay(min($this->recurringDayOfMonth ?? 1, $today->copy()->addMonth()->daysInMonth)),
        };
    }

    /**
     * @return array<int, string>
     */
    public function getDaysOfWeek(): array
    {
        return [
            0 => 'Domingo',
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableIcons(): array
    {
        return [
            '' => 'Nenhum',
            'clipboard' => 'Clipboard',
            'code-bracket' => 'Código',
            'bug-ant' => 'Bug',
            'rocket-launch' => 'Rocket',
            'wrench' => 'Ferramenta',
            'eye' => 'Olho',
            'magnifying-glass' => 'Busca',
            'chat-bubble-left-right' => 'Chat',
            'clipboard-document-list' => 'Lista',
            'calendar' => 'Calendário',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableColors(): array
    {
        return [
            '' => 'Padrão',
            'blue' => 'Azul',
            'green' => 'Verde',
            'orange' => 'Laranja',
            'purple' => 'Roxo',
            'red' => 'Vermelho',
            'zinc' => 'Cinza',
        ];
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Templates e Recorrentes</flux:heading>
    </div>

    <flux:tabs wire:model="tab">
        <flux:tab name="templates" icon="clipboard-document-list">Templates</flux:tab>
        <flux:tab name="recurring" icon="arrow-path">Recorrentes</flux:tab>
    </flux:tabs>

    <flux:tab.panel name="templates">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:text class="text-zinc-400">Templates são modelos reutilizáveis para criar tasks rapidamente.</flux:text>
                <flux:button wire:click="openTemplateForm" icon="plus" size="sm">
                    Novo Template
                </flux:button>
            </div>

            @if ($this->templates->isEmpty())
                <div class="flex flex-1 items-center justify-center py-16">
                    <div class="text-center">
                        <flux:icon name="clipboard-document-list" class="mx-auto mb-3 size-12 text-zinc-500" />
                        <flux:heading size="lg">Nenhum template</flux:heading>
                        <flux:text class="mt-1">Crie templates para agilizar a criação de tasks.</flux:text>
                    </div>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->templates as $template)
                        <div
                            wire:key="template-{{ $template->id }}"
                            class="group relative rounded-lg border border-zinc-700 bg-zinc-800/50 p-4 transition-colors hover:border-zinc-600"
                        >
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-2">
                                    @if ($template->icon)
                                        <flux:icon name="{{ $template->icon }}" class="size-5 text-{{ $template->color ?? 'zinc' }}-400" />
                                    @endif
                                    <flux:heading size="sm">{{ $template->name }}</flux:heading>
                                </div>

                                @if ($template->is_system)
                                    <flux:badge size="sm" color="zinc">Sistema</flux:badge>
                                @endif
                            </div>

                            @if ($template->description)
                                <flux:text class="mt-2 line-clamp-2 text-sm text-zinc-400">
                                    {{ Str::limit(strip_tags($template->description), 80) }}
                                </flux:text>
                            @endif

                            <div class="mt-3 flex items-center gap-2 text-xs text-zinc-500">
                                <flux:badge size="sm" color="{{ $template->default_priority->color() }}">
                                    {{ $template->default_priority->label() }}
                                </flux:badge>

                                @if ($template->default_estimated_minutes)
                                    <span>{{ $template->default_estimated_minutes }}min</span>
                                @endif
                            </div>

                            <div class="mt-4 flex items-center gap-2 border-t border-zinc-700 pt-3">
                                <flux:button
                                    wire:click="useTemplate({{ $template->id }})"
                                    size="sm"
                                    variant="primary"
                                    class="flex-1"
                                >
                                    Usar Template
                                </flux:button>

                                @unless ($template->is_system)
                                    <flux:button
                                        wire:click="openTemplateForm({{ $template->id }})"
                                        size="sm"
                                        variant="ghost"
                                        icon="pencil"
                                    />
                                    <flux:button
                                        wire:click="confirmDeleteTemplate({{ $template->id }})"
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        class="text-red-400 hover:text-red-300"
                                    />
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </flux:tab.panel>

    <flux:tab.panel name="recurring">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:text class="text-zinc-400">Tasks recorrentes são criadas automaticamente em intervalos definidos.</flux:text>
                <flux:button wire:click="openRecurringForm" icon="plus" size="sm">
                    Nova Recorrente
                </flux:button>
            </div>

            @if ($this->recurringTasks->isEmpty())
                <div class="flex flex-1 items-center justify-center py-16">
                    <div class="text-center">
                        <flux:icon name="arrow-path" class="mx-auto mb-3 size-12 text-zinc-500" />
                        <flux:heading size="lg">Nenhuma task recorrente</flux:heading>
                        <flux:text class="mt-1">Crie tasks que se repetem automaticamente.</flux:text>
                    </div>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Task</flux:table.column>
                        <flux:table.column>Frequência</flux:table.column>
                        <flux:table.column>Projeto</flux:table.column>
                        <flux:table.column>Próxima</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column align="end">Ações</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->recurringTasks as $recurring)
                            <flux:table.row :key="$recurring->id">
                                <flux:table.cell variant="strong">
                                    <div class="flex items-center gap-2">
                                        {{ $recurring->title }}
                                        <flux:badge size="sm" color="{{ $recurring->priority->color() }}">
                                            {{ $recurring->priority->label() }}
                                        </flux:badge>
                                    </div>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="{{ $recurring->frequency->icon() }}" class="size-4 text-zinc-400" />
                                        <span>{{ $recurring->frequency->label() }}</span>
                                    </div>
                                </flux:table.cell>

                                <flux:table.cell>
                                    @if ($recurring->project)
                                        <span>{{ $recurring->project->emoji }} {{ $recurring->project->name }}</span>
                                    @else
                                        <span class="text-zinc-500">—</span>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    @if ($recurring->is_active)
                                        <span class="{{ $recurring->isDue() ? 'text-amber-400' : '' }}">
                                            {{ $recurring->next_run->format('d/m/Y') }}
                                            @if ($recurring->isDue())
                                                <flux:badge size="sm" color="amber" class="ml-1">Pendente</flux:badge>
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-zinc-500">—</span>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$recurring->is_active ? 'green' : 'zinc'">
                                        {{ $recurring->is_active ? 'Ativa' : 'Pausada' }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($recurring->is_active)
                                            <flux:button
                                                wire:click="runRecurringNow({{ $recurring->id }})"
                                                size="sm"
                                                variant="ghost"
                                                icon="play"
                                                title="Criar task agora"
                                            />
                                        @endif

                                        <flux:button
                                            wire:click="toggleRecurringActive({{ $recurring->id }})"
                                            size="sm"
                                            variant="ghost"
                                            :icon="$recurring->is_active ? 'pause' : 'play'"
                                            :title="$recurring->is_active ? 'Pausar' : 'Ativar'"
                                        />

                                        <flux:button
                                            wire:click="openRecurringForm({{ $recurring->id }})"
                                            size="sm"
                                            variant="ghost"
                                            icon="pencil"
                                        />

                                        <flux:button
                                            wire:click="confirmDeleteRecurring({{ $recurring->id }})"
                                            size="sm"
                                            variant="ghost"
                                            icon="trash"
                                            class="text-red-400 hover:text-red-300"
                                        />
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    </flux:tab.panel>

    {{-- Template Form Modal --}}
    <flux:modal wire:model.self="showTemplateForm" class="md:w-[32rem]">
        <form wire:submit="saveTemplate" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $editingTemplateId ? 'Editar Template' : 'Novo Template' }}</flux:heading>
            </div>

            <flux:field>
                <flux:label>Nome</flux:label>
                <flux:input wire:model="templateName" placeholder="Ex: Code Review" />
                <flux:error name="templateName" />
            </flux:field>

            <flux:field>
                <flux:label>Descrição (Markdown)</flux:label>
                <flux:textarea wire:model="templateDescription" rows="6" placeholder="Use checkboxes: - [ ] Item" />
                <flux:error name="templateDescription" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Prioridade Padrão</flux:label>
                    <flux:select wire:model="templatePriority">
                        @foreach (TaskPriority::cases() as $priority)
                            <option value="{{ $priority->value }}">{{ $priority->label() }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>Tempo Estimado (min)</flux:label>
                    <flux:input type="number" wire:model="templateEstimatedMinutes" min="1" placeholder="30" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Ícone</flux:label>
                    <flux:select wire:model="templateIcon">
                        @foreach ($this->getAvailableIcons() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>Cor</flux:label>
                    <flux:select wire:model="templateColor">
                        @foreach ($this->getAvailableColors() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>
            </div>

            <div class="flex justify-end gap-2 border-t border-zinc-700 pt-4">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveTemplate">{{ $editingTemplateId ? 'Salvar' : 'Criar' }}</span>
                    <span wire:loading wire:target="saveTemplate">Salvando...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Recurring Task Form Modal --}}
    <flux:modal wire:model.self="showRecurringForm" class="md:w-[32rem]">
        <form wire:submit="saveRecurring" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $editingRecurringId ? 'Editar Task Recorrente' : 'Nova Task Recorrente' }}</flux:heading>
            </div>

            <flux:field>
                <flux:label>Título</flux:label>
                <flux:input wire:model="recurringTitle" placeholder="Ex: Weekly Review" />
                <flux:error name="recurringTitle" />
            </flux:field>

            <flux:field>
                <flux:label>Descrição</flux:label>
                <flux:textarea wire:model="recurringDescription" rows="3" />
                <flux:error name="recurringDescription" />
            </flux:field>

            <flux:field>
                <flux:label>Frequência</flux:label>
                <flux:select wire:model.live="recurringFrequency">
                    @foreach (RecurrenceFrequency::cases() as $frequency)
                        <option value="{{ $frequency->value }}">{{ $frequency->label() }} — {{ $frequency->description() }}</option>
                    @endforeach
                </flux:select>
            </flux:field>

            @if ($recurringFrequency === 'weekly')
                <flux:field>
                    <flux:label>Dia da Semana</flux:label>
                    <flux:select wire:model="recurringDayOfWeek">
                        @foreach ($this->getDaysOfWeek() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>
            @endif

            @if ($recurringFrequency === 'monthly')
                <flux:field>
                    <flux:label>Dia do Mês</flux:label>
                    <flux:select wire:model="recurringDayOfMonth">
                        @for ($day = 1; $day <= 31; $day++)
                            <option value="{{ $day }}">{{ $day }}</option>
                        @endfor
                    </flux:select>
                </flux:field>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Prioridade</flux:label>
                    <flux:select wire:model="recurringPriority">
                        @foreach (TaskPriority::cases() as $priority)
                            <option value="{{ $priority->value }}">{{ $priority->label() }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>Tempo Estimado (min)</flux:label>
                    <flux:input type="number" wire:model="recurringEstimatedMinutes" min="1" placeholder="30" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Projeto</flux:label>
                <flux:select wire:model="recurringProjectId">
                    <option value="">Sem projeto</option>
                    @foreach ($this->projects as $project)
                        <option value="{{ $project->id }}">{{ $project->emoji }} {{ $project->name }}</option>
                    @endforeach
                </flux:select>
            </flux:field>

            <div class="flex justify-end gap-2 border-t border-zinc-700 pt-4">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveRecurring">{{ $editingRecurringId ? 'Salvar' : 'Criar' }}</span>
                    <span wire:loading wire:target="saveRecurring">Salvando...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Template Modal --}}
    <flux:modal wire:model.self="showDeleteTemplateModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Excluir template</flux:heading>
                <flux:text class="mt-1">
                    Tem certeza que deseja excluir <strong>{{ $deletingTemplateName }}</strong>? Esta ação não pode ser desfeita.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteTemplate" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="deleteTemplate">Excluir</span>
                    <span wire:loading wire:target="deleteTemplate">Excluindo...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete Recurring Modal --}}
    <flux:modal wire:model.self="showDeleteRecurringModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Excluir task recorrente</flux:heading>
                <flux:text class="mt-1">
                    Tem certeza que deseja excluir <strong>{{ $deletingRecurringTitle }}</strong>? Esta ação não pode ser desfeita.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteRecurring" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="deleteRecurring">Excluir</span>
                    <span wire:loading wire:target="deleteRecurring">Excluindo...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
