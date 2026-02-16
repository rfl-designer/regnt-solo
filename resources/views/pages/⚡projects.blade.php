<?php

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** @var list<ProjectStatus> */
    public array $kanbanStatuses = [];

    public function mount(): void
    {
        $this->kanbanStatuses = [
            ProjectStatus::Backlog,
            ProjectStatus::Active,
            ProjectStatus::Paused,
            ProjectStatus::Done,
            ProjectStatus::Maintenance,
            ProjectStatus::Archived,
        ];
    }

    /**
     * Get projects for a specific column.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    public function getColumnProjects(ProjectStatus $status): \Illuminate\Database\Eloquent\Collection
    {
        return Project::query()
            ->where('status', $status)
            ->withCount(['tasks as active_tasks_count' => function ($query): void {
                $query->where('status', '!=', TaskStatus::Done);
            }])
            ->ordered()
            ->get();
    }

    /**
     * Get total count for a column.
     */
    public function getColumnTotal(ProjectStatus $status): int
    {
        return Project::query()->where('status', $status)->count();
    }

    public function handleSort(int|string $id, int $position, string $groupId): void
    {
        $project = Project::findOrFail((int) $id);
        $newStatus = ProjectStatus::from($groupId);

        DB::transaction(function () use ($project, $newStatus): void {
            $project->update(['status' => $newStatus]);
        });

        $this->dispatch('project-updated');
    }

    public function openProject(string $slug): void
    {
        $this->redirect(route('project.detail', $slug));
    }

    #[On('project-created')]
    #[On('project-updated')]
    public function refreshProjects(): void
    {
        // Force re-render
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col p-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Projetos</flux:heading>

        <flux:button
            variant="primary"
            icon="plus"
            wire:click="$dispatch('open-project-form')"
        >
            Novo Projeto
        </flux:button>
    </div>

    {{-- Separator --}}
    <flux:separator class="my-4" />

    {{-- Kanban Board --}}
    <div class="flex flex-1 gap-4 overflow-x-auto pb-4">
        @foreach ($kanbanStatuses as $status)
            @php
                $projects = $this->getColumnProjects($status);
                $total = $this->getColumnTotal($status);
            @endphp

            <div class="flex w-80 shrink-0 flex-col rounded-xl border border-zinc-700 bg-zinc-900/50">
                {{-- Column Header --}}
                <div class="flex items-center justify-between border-b border-zinc-700 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <flux:icon :name="$status->icon()" class="size-5 text-{{ $status->color() }}-400" />
                        <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                    </div>
                    <flux:badge size="sm" color="{{ $status->color() }}">{{ $total }}</flux:badge>
                </div>

                {{-- Projects List --}}
                <div class="flex-1 overflow-y-auto p-2">
                    <ul
                        wire:sort="handleSort"
                        wire:sort:group="projects"
                        wire:sort:group-id="{{ $status->value }}"
                        class="flex min-h-[2rem] flex-col gap-2"
                    >
                        @forelse ($projects as $project)
                            <li wire:key="project-{{ $project->id }}" wire:sort:item="{{ $project->id }}">
                                <div
                                    class="group cursor-pointer rounded-lg border border-zinc-700 bg-zinc-800 p-3 transition hover:border-zinc-500"
                                    style="border-left: 3px solid {{ $project->color }}"
                                    wire:click="openProject('{{ $project->slug }}')"
                                >
                                    {{-- Card Top: Handle + Title --}}
                                    <div class="flex items-start gap-2">
                                        <div wire:sort:handle class="mt-0.5 shrink-0 cursor-grab text-zinc-600 hover:text-zinc-400">
                                            <flux:icon name="grip-vertical" class="size-4" />
                                        </div>
                                        <div class="flex flex-1 items-center gap-2">
                                            <span class="text-lg">{{ $project->emoji }}</span>
                                            <span class="flex-1 text-sm font-medium text-zinc-200">{{ $project->name }}</span>
                                        </div>
                                    </div>

                                    {{-- Description --}}
                                    @if ($project->description)
                                        <flux:text class="mt-2 line-clamp-2 pl-6 text-xs text-zinc-400">
                                            {{ $project->description }}
                                        </flux:text>
                                    @endif

                                    {{-- Badges Row --}}
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5 pl-6">
                                        {{-- Priority Badge --}}
                                        <flux:badge size="sm" color="{{ $project->priority->color() }}" icon="{{ $project->priority->icon() }}">
                                            {{ $project->priority->label() }}
                                        </flux:badge>

                                        {{-- Tasks Count --}}
                                        <flux:badge size="sm" color="zinc" icon="clipboard-document-list">
                                            {{ $project->active_tasks_count }} {{ $project->active_tasks_count === 1 ? 'task' : 'tasks' }}
                                        </flux:badge>
                                    </div>
                                </div>
                            </li>
                        @empty
                            <li class="py-8 text-center text-sm text-zinc-600">
                                @switch($status)
                                    @case(App\Enums\ProjectStatus::Backlog)
                                        Nenhum projeto no backlog
                                        @break
                                    @case(App\Enums\ProjectStatus::Active)
                                        Nenhum projeto ativo
                                        @break
                                    @case(App\Enums\ProjectStatus::Paused)
                                        Nenhum projeto pausado
                                        @break
                                    @case(App\Enums\ProjectStatus::Done)
                                        Nenhum projeto concluído
                                        @break
                                    @case(App\Enums\ProjectStatus::Maintenance)
                                        Nenhum projeto em manutenção
                                        @break
                                    @case(App\Enums\ProjectStatus::Archived)
                                        Nenhum projeto arquivado
                                        @break
                                @endswitch
                            </li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endforeach
    </div>
</div>
