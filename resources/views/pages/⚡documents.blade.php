<?php

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Project;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $filterProject = '';

    #[Url]
    public string $filterType = '';

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Document>
     */
    #[Computed]
    public function documents(): \Illuminate\Database\Eloquent\Collection
    {
        return Document::query()
            ->with('project')
            ->when($this->filterProject !== '', function ($query) {
                if ($this->filterProject === 'global') {
                    $query->global();
                } else {
                    $query->forProject((int) $this->filterProject);
                }
            })
            ->when($this->filterType !== '', function ($query) {
                $query->byType(DocumentType::from($this->filterType));
            })
            ->ordered()
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::query()->active()->orderBy('name')->get();
    }

    public function deleteDocument(int $documentId): void
    {
        $document = Document::findOrFail($documentId);
        $title = $document->title;
        $document->delete();

        unset($this->documents);

        Flux::toast(variant: 'success', heading: 'Documento excluído', text: $title);
    }

    public function togglePin(int $documentId): void
    {
        $document = Document::findOrFail($documentId);
        $document->update(['is_pinned' => ! $document->is_pinned]);

        unset($this->documents);

        $action = $document->is_pinned ? 'fixado' : 'desafixado';
        Flux::toast(variant: 'success', heading: 'Documento '.$action, text: $document->title);
    }

    #[On('document-saved')]
    public function refreshDocuments(): void
    {
        unset($this->documents);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">Documentos</flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-400">PRDs, specs, decisões e notas do projeto.</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" href="{{ route('document.create') }}" wire:navigate>
            Novo Documento
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <flux:select wire:model.live="filterProject" placeholder="Todos os projetos" class="w-48">
            <flux:select.option value="">Todos os projetos</flux:select.option>
            <flux:select.option value="global">Sem projeto (global)</flux:select.option>
            @foreach ($this->projects as $project)
                <flux:select.option :value="$project->id">
                    {{ $project->emoji }} {{ $project->name }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filterType" placeholder="Todos os tipos" class="w-40">
            <flux:select.option value="">Todos os tipos</flux:select.option>
            @foreach (DocumentType::cases() as $type)
                <flux:select.option :value="$type->value">
                    {{ $type->label() }}
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>

    {{-- Documents Grid --}}
    @if ($this->documents->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-16">
            <flux:icon name="document-text" class="mb-4 size-12 text-zinc-600" />
            <flux:heading size="sm" class="text-zinc-400">Nenhum documento</flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-500">Crie seu primeiro PRD ou spec.</flux:text>
            <flux:button variant="primary" size="sm" icon="plus" href="{{ route('document.create') }}" wire:navigate class="mt-4">
                Novo Documento
            </flux:button>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->documents as $document)
                <flux:card wire:key="doc-{{ $document->id }}" class="group relative flex flex-col gap-3 transition hover:border-zinc-500">
                    {{-- Pin indicator --}}
                    @if ($document->is_pinned)
                        <div class="absolute -top-1.5 -right-1.5">
                            <div class="flex h-5 w-5 items-center justify-center rounded-full bg-amber-500/20 text-amber-400">
                                <flux:icon name="star" variant="micro" class="size-3" />
                            </div>
                        </div>
                    @endif

                    {{-- Header: Icon + Title + Type Badge --}}
                    <div class="flex items-start gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-{{ $document->type->color() }}-500/10">
                            <flux:icon :name="$document->type->icon()" class="size-5 text-{{ $document->type->color() }}-400" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <a href="{{ route('document.view', $document->slug) }}" wire:navigate class="line-clamp-2 text-sm font-medium text-zinc-200 hover:text-white">
                                {{ $document->title }}
                            </a>

                            <div class="mt-1 flex items-center gap-2">
                                <flux:badge size="sm" :color="$document->type->color()">
                                    {{ $document->type->label() }}
                                </flux:badge>

                                @if ($document->project)
                                    <flux:badge size="sm" color="zinc">
                                        {{ $document->project->emoji }} {{ $document->project->name }}
                                    </flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">Global</flux:badge>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Excerpt --}}
                    <flux:text class="line-clamp-3 text-xs text-zinc-500">
                        {{ $document->excerpt(150) }}
                    </flux:text>

                    {{-- Footer --}}
                    <div class="flex items-center justify-between border-t border-zinc-700/50 pt-2">
                        <flux:text class="text-xs text-zinc-600">
                            {{ $document->updated_at->diffForHumans() }}
                        </flux:text>

                        <div class="flex items-center gap-1 opacity-0 transition group-hover:opacity-100">
                            <flux:button
                                variant="ghost"
                                size="xs"
                                icon="star"
                                wire:click="togglePin({{ $document->id }})"
                                :class="$document->is_pinned ? 'text-amber-400' : ''"
                            />
                            <flux:button
                                variant="ghost"
                                size="xs"
                                icon="pencil-square"
                                href="{{ route('document.edit', $document->slug) }}"
                                wire:navigate
                            />
                            <flux:button
                                variant="ghost"
                                size="xs"
                                icon="trash"
                                wire:click="deleteDocument({{ $document->id }})"
                                wire:confirm="Tem certeza que deseja excluir este documento?"
                            />
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
