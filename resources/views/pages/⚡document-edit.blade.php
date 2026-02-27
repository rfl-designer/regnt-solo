<?php

use App\Models\Document;
use App\Models\Project;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?int $documentId = null;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string')]
    public string $content = '';

    #[Validate('required|string')]
    public string $type = 'note';

    public ?string $projectId = null;

    public bool $isPinned = false;

    public bool $isContext = false;

    public bool $showPreview = false;

    public ?string $lastSavedAt = null;

    public function mount(?string $slug = null): void
    {
        if ($slug) {
            $document = Document::query()->where('slug', $slug)->firstOrFail();

            $this->documentId = $document->id;
            $this->title = $document->title;
            $this->content = $document->content;
            $this->type = $document->type->value;
            $this->projectId = $document->project_id ? (string) $document->project_id : null;
            $this->isPinned = $document->is_pinned;
            $this->isContext = $document->is_context;
            $this->lastSavedAt = $document->updated_at->format('d/m/Y H:i');
        } else {
            // Pre-select project from query parameter (e.g., from Project Detail page)
            $projectParam = request()->query('project');
            if ($projectParam) {
                $this->projectId = (string) $projectParam;
            }
        }
    }

    /**
     * @return array<\App\Enums\DocumentType>
     */
    #[Computed]
    public function documentTypes(): array
    {
        return \App\Enums\DocumentType::cases();
    }

    #[Computed]
    public function projects(): \Illuminate\Database\Eloquent\Collection
    {
        return Project::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function generatedSlug(): string
    {
        return \Illuminate\Support\Str::slug($this->title) ?: '...';
    }

    #[Computed]
    public function isEditing(): bool
    {
        return $this->documentId !== null;
    }

    public function save(): void
    {
        $this->validate();

        $isCreating = ! $this->isEditing;

        $data = [
            'title' => $this->title,
            'content' => $this->content,
            'type' => $this->type,
            'project_id' => $this->projectId ? (int) $this->projectId : null,
            'is_pinned' => $this->isPinned,
            'is_context' => $this->isContext,
        ];

        if (! $isCreating) {
            $document = Document::findOrFail($this->documentId);
            $document->update($data);
        } else {
            $document = Document::create($data);
            $this->documentId = $document->id;
        }

        $this->lastSavedAt = now()->format('d/m/Y H:i');

        $this->dispatch('document-saved');

        Flux::toast(variant: 'success', heading: 'Documento salvo', text: $this->title);

        // If creating, redirect to edit mode with the new slug
        if ($isCreating) {
            $this->redirect(route('document.edit', $document->slug), navigate: true);
        }
    }

    public function togglePreview(): void
    {
        $this->showPreview = ! $this->showPreview;
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    {{-- Breadcrumb --}}
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('projects') }}" wire:navigate icon="folder">
            Projetos
        </flux:breadcrumbs.item>
        <flux:breadcrumbs.item>
            {{ $this->isEditing ? 'Editar Documento' : 'Novo Documento' }}
        </flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading size="xl">{{ $this->isEditing ? 'Editar Documento' : 'Novo Documento' }}</flux:heading>

        <div class="flex items-center gap-2">
            @if ($this->isEditing)
                <flux:button
                    variant="ghost"
                    icon="eye"
                    href="{{ route('document.view', Document::find($documentId)?->slug ?? '') }}"
                    wire:navigate
                >
                    Visualizar
                </flux:button>
            @endif

            <flux:button
                variant="ghost"
                icon="arrow-left"
                x-on:click="history.back()"
            >
                Voltar
            </flux:button>
        </div>
    </div>

    {{-- Form --}}
    <div class="space-y-6">
        {{-- Title + Metadata --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <flux:input
                    wire:model.blur="title"
                    label="Titulo"
                    placeholder="Titulo do documento"
                />
                <flux:text class="mt-1 text-xs text-zinc-500">
                    Slug: {{ $this->generatedSlug }}
                </flux:text>
            </div>

            <flux:select wire:model="type" label="Tipo">
                @foreach ($this->documentTypes as $docType)
                    <flux:select.option :value="$docType->value">
                        {{ $docType->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="projectId" label="Projeto" placeholder="Sem projeto">
                <flux:select.option value="">Sem projeto (global)</flux:select.option>
                @foreach ($this->projects as $project)
                    <flux:select.option :value="$project->id">
                        {{ $project->emoji }} {{ $project->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- Pinned & Context checkboxes --}}
        <div class="flex flex-col gap-3">
            <flux:checkbox wire:model="isPinned" label="Fixar documento (aparece primeiro na lista)" />
            <flux:checkbox wire:model="isContext" label="Documento de Contexto" description="Disponivel para AI assistants via MCP" />
        </div>

        {{-- Editor / Preview Toggle --}}
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <flux:label>Conteudo (Markdown)</flux:label>

                <flux:button
                    variant="ghost"
                    size="sm"
                    :icon="$showPreview ? 'pencil-square' : 'eye'"
                    wire:click="togglePreview"
                >
                    {{ $showPreview ? 'Editar' : 'Preview' }}
                </flux:button>
            </div>

            @if ($showPreview)
                <div class="min-h-[400px] rounded-xl border border-zinc-700 bg-zinc-900/50 p-6">
                    @if ($content)
                        <livewire:markdown-viewer :content="$content" :show-copy-buttons="false" />
                    @else
                        <flux:text class="text-zinc-500">Nenhum conteudo para visualizar.</flux:text>
                    @endif
                </div>
            @else
                <flux:textarea
                    wire:model="content"
                    rows="20"
                    placeholder="Escreva em Markdown..."
                    class="font-mono text-sm"
                />
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center justify-between border-t border-zinc-700 pt-4">
            <div class="flex items-center gap-4">
                @if ($lastSavedAt)
                    <flux:text class="text-xs text-zinc-500">
                        Ultima atualizacao: {{ $lastSavedAt }}
                    </flux:text>
                @endif

                @if ($content)
                    <flux:text class="text-xs text-zinc-500">
                        {{ str_word_count($content) }} palavras · {{ strlen($content) }} caracteres
                    </flux:text>
                @endif
            </div>

            <flux:button
                wire:click="save"
                variant="primary"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove wire:target="save">Salvar</span>
                <span wire:loading wire:target="save">Salvando...</span>
            </flux:button>
        </div>
    </div>
</div>
