<?php

use App\Enums\PolicyKey;
use App\Models\PolicyVersion;
use App\Services\BoardPolicyService;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The board's policy panel (issue #154).
 *
 * Lives in the Kanban header behind a "Políticas" button — no route, no
 * sidebar item — because a policy read away from the board it governs is
 * a document, and documents are exactly what this replaces.
 *
 * The panel shows three kinds of policy and treats them differently on
 * purpose: the mechanical ones are rendered from the real state and are
 * not editable here; the three written ones are edited by *appending* a
 * version; the client response agreements are read from the clients and
 * edited in /clients.
 */
new class extends Component
{
    public bool $showModal = false;

    /**
     * Which section is open in the editor, if any.
     */
    #[Locked]
    public ?string $editingKey = null;

    public string $body = '';

    public string $note = '';

    /**
     * Which section has its history open, if any.
     */
    #[Locked]
    public ?string $historyKey = null;

    public function policies(): BoardPolicyService
    {
        return app(BoardPolicyService::class);
    }

    #[Computed]
    public function mechanics(): array
    {
        return $this->policies()->mechanics();
    }

    #[Computed]
    public function sections(): array
    {
        return $this->policies()->sections();
    }

    #[Computed]
    public function agreements()
    {
        return $this->policies()->responseAgreements();
    }

    #[Computed]
    public function withoutAgreement()
    {
        return $this->policies()->clientsWithoutAgreement();
    }

    #[Computed]
    public function history()
    {
        if ($this->historyKey === null) {
            return collect();
        }

        return PolicyVersion::history(PolicyKey::from($this->historyKey));
    }

    public function open(): void
    {
        $this->resetEditor();
        $this->showModal = true;
    }

    /**
     * Open the editor of a section pre-filled with the version in force.
     * The `note` is deliberately *not* pre-filled: it explains this
     * change, not the previous one.
     */
    public function edit(string $key): void
    {
        $policyKey = PolicyKey::from($key);

        $this->editingKey = $policyKey->value;
        $this->historyKey = null;
        $this->body = PolicyVersion::current($policyKey)?->body ?? '';
        $this->note = '';
        $this->resetValidation();
    }

    /**
     * Append a new version. Never an update: the previous text stays
     * readable in the history, which is the only reason to version this
     * at all.
     */
    public function save(): void
    {
        $key = PolicyKey::from($this->editingKey ?? '');

        $this->validate([
            'body' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'body' => 'texto da política',
            'note' => 'por que mudou',
        ]);

        PolicyVersion::record($key, $this->body, $this->note);

        $this->resetEditor();

        unset($this->sections);

        Flux::toast(variant: 'success', heading: 'Política atualizada', text: "Nova versão de {$key->label()} registrada.");
    }

    public function cancel(): void
    {
        $this->resetEditor();
    }

    public function toggleHistory(string $key): void
    {
        $key = PolicyKey::from($key)->value;

        $this->historyKey = $this->historyKey === $key ? null : $key;
        $this->editingKey = null;

        unset($this->history);
    }

    private function resetEditor(): void
    {
        $this->editingKey = null;
        $this->historyKey = null;
        $this->body = '';
        $this->note = '';
        $this->resetValidation();
    }
}

?>

<div>
    <flux:button
        wire:click="open"
        size="sm"
        variant="ghost"
        icon="scale"
        data-test="board-policies-button"
    >
        Políticas
    </flux:button>

    {{-- O corpo só existe quando o painel está aberto. Renderizá-lo
         fechado custaria as leituras das três partes em *toda* renderização
         do Kanban — o quadro paga o preço de uma política que ninguém está
         lendo. --}}
    <flux:modal wire:model.self="showModal" class="w-full max-w-3xl" data-test="board-policies-modal">
        <div class="space-y-6">
        @if ($showModal)
            <div>
                <flux:heading size="lg">Políticas do quadro</flux:heading>
                <flux:subheading>O que o quadro faz sozinho, o que eu escrevi e o que combinei com cada cliente.</flux:subheading>
            </div>

            {{-- 1. Mecânicas: renderizadas do estado real, somente leitura --}}
            <div class="space-y-3">
                <flux:heading size="sm" class="text-zinc-400">Como o quadro funciona</flux:heading>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($this->mechanics as $mechanic)
                        <div
                            class="rounded-lg border border-zinc-700 bg-zinc-800 p-3"
                            data-test="policy-mechanic-{{ $mechanic['key'] }}"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium text-zinc-200">{{ $mechanic['title'] }}</span>

                                @if ($mechanic['current'])
                                    <flux:badge size="sm" color="zinc">{{ $mechanic['current'] }}</flux:badge>
                                @endif
                            </div>

                            <p class="mt-1 text-sm text-zinc-300">{{ $mechanic['statement'] }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $mechanic['detail'] }}</p>
                        </div>
                    @endforeach
                </div>

                <p class="text-xs text-zinc-500">
                    Estas não são editáveis aqui: elas são lidas da configuração e dos serviços que as aplicam, então não têm como divergir do comportamento.
                </p>
            </div>

            <flux:separator />

            {{-- 2. Políticas humanas versionadas --}}
            <div class="space-y-4">
                <flux:heading size="sm" class="text-zinc-400">O que eu escrevi</flux:heading>

                @foreach ($this->sections as $section)
                    @php
                        $key = $section['key']->value;
                        $version = $section['version'];
                    @endphp

                    <div
                        class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-4"
                        data-test="policy-section-{{ $key }}"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <flux:icon :name="$section['icon']" class="size-4 text-zinc-400" />
                                    <span class="font-medium text-zinc-100">{{ $section['label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500">{{ $section['hint'] }}</p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <flux:button
                                    wire:click="toggleHistory('{{ $key }}')"
                                    size="xs"
                                    variant="ghost"
                                    icon="clock"
                                    data-test="policy-history-toggle-{{ $key }}"
                                >
                                    Histórico ({{ $section['versions_count'] }})
                                </flux:button>

                                <flux:button
                                    wire:click="edit('{{ $key }}')"
                                    size="xs"
                                    variant="ghost"
                                    icon="pencil-square"
                                    data-test="policy-edit-{{ $key }}"
                                >
                                    Editar
                                </flux:button>
                            </div>
                        </div>

                        @if ($editingKey === $key)
                            <div class="mt-3 space-y-3" data-test="policy-editor-{{ $key }}">
                                <flux:textarea
                                    wire:model="body"
                                    rows="10"
                                    label="Texto da política (markdown)"
                                    data-test="policy-body-{{ $key }}"
                                />

                                <flux:input
                                    wire:model="note"
                                    label="Por que mudou (opcional)"
                                    placeholder="Ex.: passei a exigir teste antes de Feito"
                                    data-test="policy-note-{{ $key }}"
                                />

                                <p class="text-xs text-zinc-500">
                                    Salvar cria uma versão nova. A anterior continua no histórico — nada é sobrescrito.
                                </p>

                                <div class="flex items-center gap-2">
                                    <flux:button
                                        wire:click="save"
                                        size="sm"
                                        variant="primary"
                                        data-test="policy-save-{{ $key }}"
                                    >
                                        Salvar versão
                                    </flux:button>

                                    <flux:button wire:click="cancel" size="sm" variant="ghost">
                                        Cancelar
                                    </flux:button>
                                </div>
                            </div>
                        @elseif ($version)
                            <div class="markdown-viewer mt-3 text-sm text-zinc-300">
                                {!! Str::markdown($version->body) !!}
                            </div>

                            <p class="mt-2 text-xs text-zinc-500">
                                Vigente desde {{ $version->created_at?->format('d/m/Y') }}
                                @if ($version->note)
                                    — {{ $version->note }}
                                @endif
                            </p>
                        @else
                            <p class="mt-3 text-sm text-zinc-500" data-test="policy-empty-{{ $key }}">
                                Ainda não escrita. Escrever custa dois minutos e evita discutir de novo daqui a um mês.
                            </p>
                        @endif

                        @if ($historyKey === $key)
                            <div class="mt-3 space-y-2 border-t border-zinc-700 pt-3" data-test="policy-history-{{ $key }}">
                                @forelse ($this->history as $entry)
                                    <div class="rounded-lg border border-zinc-700 bg-zinc-800 p-2 text-xs">
                                        <div class="flex items-center justify-between gap-2 text-zinc-400">
                                            <span>{{ $entry->created_at?->format('d/m/Y H:i') }}</span>
                                            @if ($loop->first)
                                                <flux:badge size="sm" color="emerald">Vigente</flux:badge>
                                            @endif
                                        </div>

                                        @if ($entry->note)
                                            <p class="mt-1 text-zinc-300">{{ $entry->note }}</p>
                                        @else
                                            <p class="mt-1 text-zinc-500">Sem nota</p>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-xs text-zinc-500">Nenhuma versão registrada ainda.</p>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <flux:separator />

            {{-- 3. Acordos de resposta dos clientes: leitura --}}
            <div class="space-y-3" data-test="policy-response-agreements">
                <div class="flex items-center justify-between gap-2">
                    <flux:heading size="sm" class="text-zinc-400">Acordos de resposta</flux:heading>

                    <a href="{{ route('clients') }}" wire:navigate data-test="policy-clients-link">
                        <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square">
                            Editar em Clientes
                        </flux:button>
                    </a>
                </div>

                @forelse ($this->agreements as $client)
                    <div
                        class="flex items-start gap-3 rounded-lg border border-zinc-700 bg-zinc-800 p-3"
                        data-test="policy-agreement-{{ $client->id }}"
                    >
                        <span
                            class="mt-1 size-2.5 shrink-0 rounded-full"
                            style="background-color: {{ $client->color }}"
                        ></span>

                        <div>
                            <span class="text-sm font-medium text-zinc-200">{{ $client->name }}</span>
                            <p class="text-sm text-zinc-400">{{ $client->response_agreement }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">Nenhum cliente ativo com acordo de resposta definido.</p>
                @endforelse

                @if ($this->withoutAgreement->isNotEmpty())
                    <a
                        href="{{ route('clients') }}"
                        wire:navigate
                        class="block text-xs text-amber-400 hover:text-amber-300"
                        data-test="policy-agreement-nudge"
                    >
                        {{ $this->withoutAgreement->count().' '.($this->withoutAgreement->count() === 1 ? 'cliente ativo sem acordo' : 'clientes ativos sem acordo').' — definir' }}
                    </a>
                @endif
            </div>
        @endif
        </div>
    </flux:modal>
</div>
