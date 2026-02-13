<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $projectId = null;

    public string $name = '';

    public string $emoji = '📋';

    public string $color = '#3b82f6';

    public string $priority = 'medium';

    public string $status = 'active';

    public string $description = '';

    #[On('open-project-form')]
    public function openForm(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    #[On('edit-project')]
    public function editProject(int $projectId): void
    {
        $project = Project::findOrFail($projectId);

        $this->projectId = $project->id;
        $this->name = $project->name;
        $this->emoji = $project->emoji;
        $this->color = $project->color;
        $this->priority = $project->priority->value;
        $this->status = $project->status->value;
        $this->description = $project->description ?? '';

        $this->showModal = true;
    }

    public function saveProject(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'emoji' => 'required|string|max:10',
            'color' => 'required|string|max:7',
            'priority' => 'required|in:'.implode(',', array_column(ProjectPriority::cases(), 'value')),
            'status' => 'required|in:'.implode(',', array_column(ProjectStatus::cases(), 'value')),
            'description' => 'nullable|string',
        ]);

        $slug = Str::slug($this->name);

        $slugExists = Project::where('slug', $slug)
            ->when($this->projectId, fn ($query) => $query->where('id', '!=', $this->projectId))
            ->exists();

        if ($slugExists) {
            $this->addError('name', 'Já existe um projeto com este nome.');

            return;
        }

        $data = [
            'name' => $this->name,
            'slug' => $slug,
            'emoji' => $this->emoji,
            'color' => $this->color,
            'priority' => $this->priority,
            'status' => $this->status,
            'description' => $this->description ?: null,
        ];

        if ($this->projectId) {
            $project = Project::findOrFail($this->projectId);
            $project->update($data);

            $this->dispatch('project-updated');
            Flux::toast(variant: 'success', heading: 'Projeto atualizado', text: $this->name);
        } else {
            Project::create($data);

            $this->dispatch('project-created');
            Flux::toast(variant: 'success', heading: 'Projeto criado', text: $this->name);
        }

        $this->showModal = false;
    }

    private function resetForm(): void
    {
        $this->reset('projectId', 'name', 'emoji', 'color', 'priority', 'status', 'description');
    }
};

?>

<div>
    <flux:modal wire:model.self="showModal" class="md:w-xl">
        <div class="space-y-6">
            {{-- Header --}}
            <div>
                <flux:heading size="lg">{{ $projectId ? 'Editar Projeto' : 'Novo Projeto' }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $projectId ? 'Atualize os detalhes do projeto.' : 'Preencha os dados para criar um novo projeto.' }}
                </flux:text>
            </div>

            {{-- Nome --}}
            <flux:input
                wire:model="name"
                label="Nome"
                placeholder="Nome do projeto"
            />

            {{-- Two-column grid for emoji and color --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Emoji --}}
                <flux:input
                    wire:model="emoji"
                    label="Emoji"
                    placeholder="📋"
                    maxlength="10"
                />

                {{-- Cor --}}
                <flux:field>
                    <flux:label>Cor</flux:label>
                    <input
                        type="color"
                        wire:model="color"
                        class="h-10 w-full cursor-pointer rounded-lg border border-zinc-300 bg-white p-1 dark:border-zinc-600 dark:bg-zinc-700"
                    />
                    <flux:error name="color" />
                </flux:field>
            </div>

            {{-- Two-column grid for priority and status --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Prioridade --}}
                <flux:select wire:model="priority" label="Prioridade">
                    @foreach (ProjectPriority::cases() as $p)
                        <flux:select.option :value="$p->value">
                            {{ $p->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                {{-- Status --}}
                <flux:select wire:model="status" label="Status">
                    @foreach (ProjectStatus::cases() as $s)
                        <flux:select.option :value="$s->value">
                            {{ $s->label() }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            {{-- Descrição --}}
            <flux:textarea
                wire:model="description"
                label="Descrição"
                placeholder="Descreva o projeto..."
                rows="3"
            />

            {{-- Footer --}}
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveProject" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveProject">Salvar</span>
                    <span wire:loading wire:target="saveProject">Salvando...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
