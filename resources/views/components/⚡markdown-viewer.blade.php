<?php

use Livewire\Component;

new class extends Component
{
    public string $content = '';

    public ?string $title = null;

    public bool $showCopyButtons = true;
}

?>

<div>
    @if ($title)
        <div class="mb-4 flex items-center justify-between">
            <flux:heading size="lg">{{ $title }}</flux:heading>

            @if ($showCopyButtons)
                <div class="flex items-center gap-2">
                    <flux:button
                        variant="ghost"
                        size="sm"
                        icon="clipboard-document"
                        x-on:click="
                            navigator.clipboard.writeText(@js($content));
                            $flux.toast({ heading: 'Copiado', text: 'Markdown copiado para a área de transferência', variant: 'success' });
                        "
                    >
                        Copiar MD
                    </flux:button>

                    <flux:button
                        variant="ghost"
                        size="sm"
                        icon="code-bracket"
                        x-on:click="
                            navigator.clipboard.writeText($refs.renderedContent.innerHTML);
                            $flux.toast({ heading: 'Copiado', text: 'HTML copiado para a área de transferência', variant: 'success' });
                        "
                    >
                        Copiar HTML
                    </flux:button>
                </div>
            @endif
        </div>
    @elseif ($showCopyButtons)
        <div class="mb-2 flex justify-end gap-2">
            <flux:button
                variant="ghost"
                size="xs"
                icon="clipboard-document"
                x-on:click="
                    navigator.clipboard.writeText(@js($content));
                    $flux.toast({ heading: 'Copiado', text: 'Markdown copiado', variant: 'success' });
                "
            >
                MD
            </flux:button>

            <flux:button
                variant="ghost"
                size="xs"
                icon="code-bracket"
                x-on:click="
                    navigator.clipboard.writeText($refs.renderedContent.innerHTML);
                    $flux.toast({ heading: 'Copiado', text: 'HTML copiado', variant: 'success' });
                "
            >
                HTML
            </flux:button>
        </div>
    @endif

    <div
        x-ref="renderedContent"
        class="markdown-viewer"
        x-data
        x-init="
            if ($el.querySelectorAll('pre code').length > 0) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css';
                document.head.appendChild(link);

                const script = document.createElement('script');
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js';
                script.onload = () => hljs.highlightAll();
                document.head.appendChild(script);
            }
        "
    >
        {!! \App\Support\Markdown::render($content) !!}

    </div>
</div>
