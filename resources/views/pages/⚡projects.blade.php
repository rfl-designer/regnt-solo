<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $statusFilter = 'active';

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::query()
            ->where('status', $this->statusFilter)
            ->withCount(['tasks as active_tasks_count' => function ($query): void {
                $query->where('status', '!=', TaskStatus::Done);
            }])
            ->ordered()
            ->get();
    }

    public function openProject(string $slug): void
    {
        $this->redirect(route('project.detail', $slug));
    }

    #[On('project-created')]
    #[On('project-updated')]
    public function refreshProjects(): void
    {
        unset($this->projects);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading size="xl">Projetos</flux:heading>

        <flux:button
            variant="primary"
            icon="plus"
            wire:click="$dispatch('open-project-form')"
        >
            Novo Projeto
        </flux:button>
    </div>

    {{-- Status Tabs --}}
    <flux:tabs wire:model.live="statusFilter">
        <flux:tab name="active" icon="play-circle">Ativos</flux:tab>
        <flux:tab name="paused" icon="pause-circle">Pausados</flux:tab>
        <flux:tab name="archived" icon="archive-box">Arquivados</flux:tab>
    </flux:tabs>

    {{-- Projects Grid --}}
    @if ($this->projects->isEmpty())
        <div class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <flux:icon name="folder" class="mx-auto mb-3 size-12 text-zinc-500" />
                <flux:heading size="lg">Nenhum projeto encontrado</flux:heading>
                <flux:text class="mt-1">
                    @if ($statusFilter === 'active')
                        Crie seu primeiro projeto clicando em "Novo Projeto".
                    @elseif ($statusFilter === 'paused')
                        Nenhum projeto pausado no momento.
                    @else
                        Nenhum projeto arquivado no momento.
                    @endif
                </flux:text>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->projects as $project)
                <flux:card
                    wire:key="project-{{ $project->id }}"
                    class="cursor-pointer border-l-4 transition hover:border-zinc-500"
                    style="border-left-color: {{ $project->color }}"
                    wire:click="openProject('{{ $project->slug }}')"
                >
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-xl">{{ $project->emoji }}</span>
                            <flux:heading size="sm">{{ $project->name }}</flux:heading>
                        </div>

                        <flux:badge size="sm" color="{{ $project->status->color() }}" icon="{{ $project->status->icon() }}">
                            {{ $project->status->label() }}
                        </flux:badge>
                    </div>

                    @if ($project->description)
                        <flux:text class="mt-2 line-clamp-2 text-sm">
                            {{ $project->description }}
                        </flux:text>
                    @endif

                    <div class="mt-3 flex items-center gap-3">
                        <div class="flex items-center gap-1.5 text-sm text-zinc-400">
                            <flux:icon name="clipboard-document-list" class="size-4" />
                            <span>{{ $project->active_tasks_count }} {{ $project->active_tasks_count === 1 ? 'task ativa' : 'tasks ativas' }}</span>
                        </div>

                        <flux:badge size="sm" color="{{ $project->priority->color() }}" icon="{{ $project->priority->icon() }}">
                            {{ $project->priority->label() }}
                        </flux:badge>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
