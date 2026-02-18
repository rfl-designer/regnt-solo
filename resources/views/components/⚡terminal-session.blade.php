@php
use Livewire\Volt\Component;
use Livewire\Attributes\Reactive;

new class extends Component {
    #[Reactive]
    public int $taskId;

    #[Reactive]
    public string $sessionPrompt = '';

    #[Reactive]
    public bool $fullscreen = false;

    public function getServerUrl(): string
    {
        return config('soloboard.terminal_server_url', 'ws://localhost:3001');
    }
}
@endphp

<div
    x-data="terminalSession({
        taskId: @js($taskId),
        sessionPrompt: @js($sessionPrompt),
        serverUrl: @js($this->getServerUrl()),
    })"
    x-init="init()"
    @class([
        'flex flex-col bg-zinc-900 rounded-xl border border-zinc-700 overflow-hidden',
        'fixed inset-4 z-50' => $fullscreen,
    ])
>
    {{-- Terminal Header --}}
    <div class="flex items-center justify-between px-4 py-2 bg-zinc-800/50 border-b border-zinc-700">
        <div class="flex items-center gap-3">
            {{-- Traffic lights --}}
            <div class="flex items-center gap-1.5">
                <button
                    @click="$dispatch('close-terminal')"
                    class="w-3 h-3 rounded-full bg-red-500 hover:bg-red-400 transition-colors"
                    title="Fechar"
                ></button>
                <button
                    @click="clear()"
                    class="w-3 h-3 rounded-full bg-yellow-500 hover:bg-yellow-400 transition-colors"
                    title="Limpar"
                ></button>
                <button
                    @click="$dispatch('toggle-fullscreen')"
                    class="w-3 h-3 rounded-full bg-green-500 hover:bg-green-400 transition-colors"
                    title="Tela cheia"
                ></button>
            </div>

            {{-- Title --}}
            <span class="text-sm text-zinc-400">
                Claude Code — Task #{{ $taskId }}
            </span>

            {{-- Connection status --}}
            <span
                x-show="connected"
                class="flex items-center gap-1 text-xs text-emerald-400"
            >
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                Conectado
            </span>
            <span
                x-show="!connected && reconnecting"
                class="flex items-center gap-1 text-xs text-amber-400"
            >
                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                Reconectando...
            </span>
            <span
                x-show="!connected && !reconnecting"
                class="flex items-center gap-1 text-xs text-red-400"
            >
                <span class="w-2 h-2 rounded-full bg-red-500"></span>
                Desconectado
            </span>
        </div>

        <div class="flex items-center gap-2">
            {{-- Quick commands --}}
            <flux:button
                size="xs"
                variant="ghost"
                @click="sendCommand('y')"
                title="Enviar 'y' (yes)"
            >
                y
            </flux:button>
            <flux:button
                size="xs"
                variant="ghost"
                @click="sendCommand('n')"
                title="Enviar 'n' (no)"
            >
                n
            </flux:button>
            <flux:button
                size="xs"
                variant="ghost"
                icon="stop"
                @click="sendCommand('\x03')"
                title="Ctrl+C (cancelar)"
            />
        </div>
    </div>

    {{-- Terminal Container --}}
    <div
        x-ref="terminal"
        class="flex-1 min-h-0 p-2"
        x-on:resize.window="fit()"
    ></div>

    {{-- Session Prompt Preview (collapsed) --}}
    @if($sessionPrompt)
    <div
        x-data="{ expanded: false }"
        class="border-t border-zinc-700"
    >
        <button
            @click="expanded = !expanded"
            class="w-full flex items-center justify-between px-4 py-2 text-sm text-zinc-400 hover:bg-zinc-800/50 transition-colors"
        >
            <span class="flex items-center gap-2">
                <flux:icon.document-text class="w-4 h-4" />
                Session Prompt
            </span>
            <flux:icon.chevron-down
                x-bind:class="{ 'rotate-180': expanded }"
                class="w-4 h-4 transition-transform"
            />
        </button>
        <div
            x-show="expanded"
            x-collapse
            class="px-4 py-3 bg-zinc-800/30 text-sm text-zinc-300 prose prose-invert prose-sm max-w-none"
        >
            {!! Str::markdown($sessionPrompt) !!}
        </div>
    </div>
    @endif
</div>
