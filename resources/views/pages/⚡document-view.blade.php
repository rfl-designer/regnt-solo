<?php

use App\Models\Document;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $slug = '';

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    #[Computed]
    public function document(): Document
    {
        return Document::query()
            ->with('project')
            ->where('slug', $this->slug)
            ->firstOrFail();
    }

    public function togglePin(): void
    {
        $document = $this->document;
        $document->update(['is_pinned' => ! $document->is_pinned]);

        unset($this->document);

        $action = $document->fresh()->is_pinned ? 'fixado' : 'desafixado';
        Flux::toast(variant: 'success', heading: 'Documento '.$action, text: $document->title);
    }

    public function deleteDocument(): void
    {
        $document = $this->document;
        $title = $document->title;
        $document->delete();

        Flux::toast(variant: 'success', heading: 'Documento excluído', text: $title);

        $this->redirect(route('documents'), navigate: true);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    {{-- Breadcrumb --}}
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('documents') }}" wire:navigate icon="document-text">
            Docs
        </flux:breadcrumbs.item>
        @if ($this->document->project)
            <flux:breadcrumbs.item href="{{ route('project.detail', $this->document->project->slug) }}" wire:navigate>
                {{ $this->document->project->emoji }} {{ $this->document->project->name }}
            </flux:breadcrumbs.item>
        @endif
        <flux:breadcrumbs.item>
            {{ $this->document->title }}
        </flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-{{ $this->document->type->color() }}-500/10">
                <flux:icon :name="$this->document->type->icon()" class="size-6 text-{{ $this->document->type->color() }}-400" />
            </div>

            <div>
                <flux:heading size="xl">{{ $this->document->title }}</flux:heading>
                <div class="mt-1 flex items-center gap-2">
                    <flux:badge size="sm" :color="$this->document->type->color()">
                        {{ $this->document->type->label() }}
                    </flux:badge>
                    @if ($this->document->project)
                        <flux:badge size="sm" color="zinc">
                            {{ $this->document->project->emoji }} {{ $this->document->project->name }}
                        </flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc">Global</flux:badge>
                    @endif
                    @if ($this->document->is_pinned)
                        <flux:badge size="sm" color="amber" icon="star">Fixado</flux:badge>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                variant="ghost"
                icon="arrow-left"
                href="{{ route('documents') }}"
                wire:navigate
            >
                Voltar
            </flux:button>

            <flux:button
                variant="ghost"
                icon="star"
                wire:click="togglePin"
                :class="$this->document->is_pinned ? 'text-amber-400' : ''"
            >
                {{ $this->document->is_pinned ? 'Desafixar' : 'Fixar' }}
            </flux:button>

            <flux:button
                variant="ghost"
                icon="pencil-square"
                href="{{ route('document.edit', $this->document->slug) }}"
                wire:navigate
            >
                Editar
            </flux:button>

            <flux:button
                variant="ghost"
                icon="trash"
                wire:click="deleteDocument"
                wire:confirm="Tem certeza que deseja excluir este documento?"
            >
                Excluir
            </flux:button>
        </div>
    </div>

    {{-- Content --}}
    <div class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-6">
        <livewire:markdown-viewer :content="$this->document->content" />
    </div>

    {{-- Footer --}}
    <div class="flex items-center gap-4 text-xs text-zinc-500">
        <span>Criado {{ $this->document->created_at->format('d/m/Y H:i') }}</span>
        <span>Atualizado {{ $this->document->updated_at->diffForHumans() }}</span>
    </div>
</div>
