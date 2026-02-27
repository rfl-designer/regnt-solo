<?php

use App\Models\Document;
use Barryvdh\DomPDF\Facade\Pdf;
use Flux\Flux;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function exportPdf(): StreamedResponse
    {
        $document = $this->document;

        // Convert markdown to HTML
        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $htmlContent = $converter->convert($document->content)->getContent();

        // Get type color for header badge
        $typeColors = [
            'prd' => '#3b82f6',      // blue
            'spec' => '#8b5cf6',     // violet
            'decision' => '#f59e0b', // amber
            'note' => '#6b7280',     // gray
            'reference' => '#10b981', // emerald
        ];
        $typeColor = $typeColors[$document->type->value] ?? '#6b7280';

        $pdf = Pdf::loadView('pdf.document', [
            'document' => $document,
            'htmlContent' => $htmlContent,
            'typeColor' => $typeColor,
        ]);

        $filename = Str::slug($document->title).'.pdf';

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    public function deleteDocument(): void
    {
        $document = $this->document;
        $title = $document->title;
        $projectSlug = $document->project?->slug;
        $document->delete();

        Flux::toast(variant: 'success', heading: 'Documento excluído', text: $title);

        $redirectRoute = $projectSlug
            ? route('project.detail', $projectSlug).'?tab=docs'
            : route('projects');

        $this->redirect($redirectRoute, navigate: true);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    {{-- Breadcrumb --}}
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('projects') }}" wire:navigate icon="folder">
            Projetos
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
                <div class="flex items-center gap-3">
                    <flux:heading size="xl">{{ $this->document->title }}</flux:heading>
                    <span
                        x-data="{ copied: false }"
                        x-on:click="navigator.clipboard.writeText('#D-{{ $this->document->id }}'); copied = true; setTimeout(() => copied = false, 1500)"
                        class="cursor-pointer text-sm font-mono transition-colors duration-200"
                        :class="copied ? 'text-emerald-400' : 'text-zinc-500 hover:text-zinc-300'"
                        title="Copiar ID"
                    >
                        <span x-show="!copied">#D-{{ $this->document->id }}</span>
                        <span x-show="copied" x-cloak>Copiado!</span>
                    </span>
                </div>
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
                    @if ($this->document->is_context)
                        <flux:badge size="sm" color="violet" icon="cpu-chip">Contexto</flux:badge>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                variant="ghost"
                icon="arrow-left"
                x-on:click="history.back()"
            >
                Voltar
            </flux:button>

            <flux:button
                variant="ghost"
                icon="arrow-down-tray"
                wire:click="exportPdf"
            >
                Exportar PDF
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
