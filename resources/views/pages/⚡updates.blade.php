<?php

use App\Models\Client;
use App\Models\ClientUpdate;
use App\Services\ClientUpdateQueueEntry;
use App\Services\ClientUpdateService;
use App\Exceptions\UpdateAlreadySentException;
use App\Exceptions\UpdateDraftHasManualEditsException;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A página Updates (issue #149): a fila de quem está devido, o rascunho da
 * semana e o histórico do que cada cliente recebeu.
 *
 * A página não calcula nada. Fila, urgência, janela e texto vêm todos de
 * {@see ClientUpdateService}, que é o mesmo objeto que as três tools de MCP
 * chamam — é o que garante que gerar por aqui e gerar por lá produza o
 * mesmo rascunho.
 *
 * "Copiar" e "Marcar como enviado" são atos separados de propósito: copiar
 * não grava nada, e é enviar que zera o relógio da cadência. O histórico
 * fica sendo o que o cliente de fato recebeu, não o que foi rascunhado.
 */
new class extends Component
{
    /**
     * O cliente selecionado, pelo slug — é ele que a página Clientes passa
     * no atalho "Gerar update", e é ele que sobrevive a um refresh.
     */
    #[Url]
    public ?string $client = null;

    /**
     * O texto do rascunho em edição. Persiste no blur, como a reflexão da
     * Revisão semanal.
     */
    public string $content = '';

    public bool $showRegenerateConfirm = false;

    public function mount(): void
    {
        $this->syncSelection();
    }

    /**
     * @return \Illuminate\Support\Collection<int, ClientUpdateQueueEntry>
     */
    #[Computed]
    public function queue(): \Illuminate\Support\Collection
    {
        return app(ClientUpdateService::class)->queue();
    }

    #[Computed]
    public function selected(): ?Client
    {
        if ($this->client === null) {
            return null;
        }

        return Client::query()->where('slug', $this->client)->first();
    }

    #[Computed]
    public function draft(): ?ClientUpdate
    {
        $client = $this->selected;

        return $client === null ? null : app(ClientUpdateService::class)->draftFor($client);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ClientUpdate>
     */
    #[Computed]
    public function history(): \Illuminate\Database\Eloquent\Collection
    {
        $client = $this->selected;

        return $client === null
            ? new \Illuminate\Database\Eloquent\Collection
            : app(ClientUpdateService::class)->history($client);
    }

    /**
     * A janela que o próximo rascunho cobre, para a página dizer desde
     * quando ela está contando.
     */
    #[Computed]
    public function windowStart(): ?\Carbon\CarbonInterface
    {
        $client = $this->selected;

        return $client === null ? null : app(ClientUpdateService::class)->windowStart($client);
    }

    public function select(string $slug): void
    {
        $this->client = $slug;
        $this->syncSelection();
    }

    /**
     * Gera o rascunho do zero. Só aparece quando não há rascunho aberto —
     * com um aberto, o botão é "Regenerar", que passa pela confirmação.
     */
    public function generate(): void
    {
        $client = $this->selected;

        if ($client === null) {
            return;
        }

        $this->persistDraft();

        $draft = app(ClientUpdateService::class)->generate($client, force: true);

        $this->content = $draft->content;
        $this->refreshClientState();

        Flux::toast(variant: 'success', heading: 'Rascunho gerado', text: 'Do estado real do quadro.');
    }

    /**
     * Pede confirmação antes de descartar edição manual; sem edição, regera
     * direto — não há nada a perder.
     */
    public function requestRegenerate(): void
    {
        $client = $this->selected;

        if ($client === null) {
            return;
        }

        // O texto na tela pode estar à frente do banco (o blur ainda não
        // aconteceu), e é ele que o usuário arriscaria perder.
        $this->persistDraft();

        try {
            $draft = app(ClientUpdateService::class)->generate($client);
        } catch (UpdateDraftHasManualEditsException) {
            $this->showRegenerateConfirm = true;

            return;
        }

        $this->content = $draft->content;
        $this->refreshClientState();

        Flux::toast(variant: 'success', heading: 'Rascunho regerado', text: 'Do estado real do quadro.');
    }

    public function regenerate(): void
    {
        $client = $this->selected;

        if ($client === null) {
            return;
        }

        $draft = app(ClientUpdateService::class)->generate($client, force: true);

        $this->content = $draft->content;
        $this->showRegenerateConfirm = false;
        $this->refreshClientState();

        Flux::toast(variant: 'success', heading: 'Rascunho regerado', text: 'A edição anterior foi descartada.');
    }

    /**
     * Copiar não grava: leva para a área de transferência exatamente o que
     * está na tela e vai embora.
     */
    public function copy(): void
    {
        if ($this->content === '') {
            return;
        }

        $this->dispatch('copy-to-clipboard', markdown: $this->content);

        Flux::toast(variant: 'success', heading: 'Copiado!', text: 'O update foi para a área de transferência.');
    }

    /**
     * Marcar como enviado grava a data — e é a data que zera o relógio da
     * cadência e define desde quando o próximo update cobre.
     */
    public function markSent(): void
    {
        $draft = $this->draft;

        if ($draft === null) {
            return;
        }

        $this->persistDraft();

        try {
            app(ClientUpdateService::class)->markSent($draft);
        } catch (UpdateAlreadySentException $e) {
            Flux::toast(variant: 'danger', heading: 'Nada a enviar', text: $e->getMessage());

            return;
        }

        $this->content = '';
        $this->refreshClientState();
        $this->dispatch('client-update-sent');

        Flux::toast(variant: 'success', heading: 'Marcado como enviado', text: 'O relógio da cadência zerou.');
    }

    /**
     * Autosave do editor.
     */
    public function updatedContent(): void
    {
        $this->persistDraft();
    }

    private function persistDraft(): void
    {
        $draft = $this->draft;

        if ($draft === null || $draft->content === $this->content) {
            return;
        }

        $draft->update(['content' => $this->content]);

        unset($this->draft);
    }

    /**
     * Carrega no editor o rascunho do cliente selecionado (ou o esvazia).
     */
    private function syncSelection(): void
    {
        unset($this->selected, $this->draft, $this->history, $this->windowStart);

        $this->showRegenerateConfirm = false;
        $this->content = $this->draft?->content ?? '';
    }

    private function refreshClientState(): void
    {
        unset($this->queue, $this->draft, $this->history, $this->windowStart);
    }
};

?>

<div
    class="flex h-full w-full flex-1 flex-col gap-6 p-6"
    x-data
    @copy-to-clipboard.window="
        navigator.clipboard.writeText($event.detail.markdown).catch(() => {
            $flux.toast({ variant: 'danger', heading: 'Erro', text: 'Não foi possível copiar.' })
        })
    "
>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Updates</flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-400">
                Um update por cliente, por semana — montado do quadro, enviado por você.
            </flux:text>
        </div>
    </div>

    <div class="grid flex-1 grid-cols-1 gap-6 lg:grid-cols-[20rem_1fr]">
        {{-- Fila de clientes, por urgência --}}
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-700 bg-zinc-900/50 p-4" data-test="update-queue">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">Fila</flux:heading>
                <flux:text class="text-xs text-zinc-500">{{ $this->queue->count() }} {{ \Illuminate\Support\Str::plural('cliente', $this->queue->count()) }}</flux:text>
            </div>

            @forelse ($this->queue as $entry)
                <button
                    type="button"
                    wire:key="queue-{{ $entry->client->id }}"
                    wire:click="select('{{ $entry->client->slug }}')"
                    @class([
                        'w-full rounded-lg border p-3 text-left transition',
                        'border-blue-500/60 bg-zinc-800' => $this->client === $entry->client->slug,
                        'border-zinc-700 bg-zinc-800 hover:border-zinc-500' => $this->client !== $entry->client->slug,
                    ])
                    data-test="queue-item-{{ $entry->client->slug }}"
                >
                    <div class="flex items-center gap-2">
                        <div class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $entry->client->color }}"></div>
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-200">{{ $entry->client->name }}</span>
                        <flux:icon
                            :name="$entry->client->channel->icon()"
                            class="size-4 shrink-0 text-zinc-500"
                            :title="$entry->client->channel->label()"
                        />
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="$entry->urgency->color()" :icon="$entry->urgency->icon()">
                            {{ $entry->urgency->label() }}
                        </flux:badge>
                        @if ($entry->hasDraft())
                            <flux:badge size="sm" color="zinc" icon="pencil">rascunho</flux:badge>
                        @endif
                    </div>

                    <div class="mt-2 text-xs text-zinc-500">
                        {{ $entry->urgencyPhrase() }} &middot; último envio {{ $entry->lastSentPhrase() }}
                    </div>
                </button>
            @empty
                <div class="py-8 text-center">
                    <flux:icon name="building-office-2" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm">Nenhum cliente ativo.</flux:text>
                    <flux:button :href="route('clients')" wire:navigate size="xs" variant="ghost" class="mt-3">
                        Cadastrar cliente
                    </flux:button>
                </div>
            @endforelse
        </div>

        {{-- Rascunho + histórico --}}
        <div class="flex flex-col gap-6">
            @if ($this->selected === null)
                <div class="flex flex-1 items-center justify-center rounded-xl border border-dashed border-zinc-700 bg-zinc-900/50 p-10">
                    <div class="text-center">
                        <flux:icon name="paper-airplane" class="mx-auto mb-3 size-10 text-zinc-600" />
                        <flux:heading size="lg">Escolha um cliente</flux:heading>
                        <flux:text class="mt-1">O rascunho é montado do que aconteceu desde o último envio.</flux:text>
                    </div>
                </div>
            @else
                <div class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-4" data-test="update-draft">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <flux:heading size="lg">{{ $this->selected->name }}</flux:heading>
                            <flux:text class="mt-1 text-xs text-zinc-500">
                                Cobre desde {{ $this->windowStart?->timezone(config('soloboard.timezone'))->format('d/m/Y H:i') }}
                                &middot; {{ $this->selected->updateDayLabel() }}
                                @if ($this->selected->update_time)
                                    às {{ $this->selected->update_time->format('H:i') }}
                                @endif
                                &middot; {{ $this->selected->channel->label() }}
                            </flux:text>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @if ($this->draft)
                                <flux:button wire:click="requestRegenerate" size="sm" variant="ghost" icon="arrow-path" data-test="regenerate-update">
                                    Regenerar
                                </flux:button>
                                <flux:button wire:click="copy" size="sm" variant="ghost" icon="clipboard-document" data-test="copy-update">
                                    Copiar
                                </flux:button>
                                <flux:button wire:click="markSent" size="sm" variant="primary" icon="paper-airplane" data-test="mark-update-sent">
                                    Marcar como enviado
                                </flux:button>
                            @else
                                <flux:button wire:click="generate" size="sm" variant="primary" icon="sparkles" data-test="generate-update">
                                    Gerar update
                                </flux:button>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4">
                        @if ($this->draft)
                            {{-- `.live.blur`, não `.blur`: desde o Livewire
                                 4.1 o modificador sozinho só sincroniza o
                                 valor dentro do browser, sem mandar
                                 requisição — um autosave que nunca salva. --}}
                            <flux:textarea
                                wire:model.live.blur="content"
                                rows="16"
                                class="font-mono text-sm"
                                data-test="update-editor"
                            />
                            <flux:text class="mt-2 text-xs text-zinc-500">
                                Salva sozinho ao sair do campo. Copiar não marca como enviado.
                            </flux:text>
                        @else
                            <div class="flex items-center justify-center rounded-lg border border-dashed border-zinc-700 bg-zinc-800/30 p-8 text-center">
                                <div>
                                    <flux:text class="text-sm">Nenhum rascunho aberto para este cliente.</flux:text>
                                    <flux:text class="mt-1 text-xs text-zinc-500">
                                        Gerar monta os quatro blocos do estado real do quadro.
                                    </flux:text>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Histórico: o que o cliente de fato recebeu --}}
                <div class="rounded-xl border border-zinc-700 bg-zinc-900/50 p-4" data-test="update-history">
                    <flux:heading size="sm">Histórico</flux:heading>

                    @forelse ($this->history as $update)
                        <div class="mt-3 rounded-lg border border-zinc-700 bg-zinc-800 p-3" wire:key="history-{{ $update->id }}">
                            <div class="flex items-center gap-2">
                                <flux:icon name="paper-airplane" class="size-3.5 text-emerald-400" />
                                <span class="text-xs font-medium text-zinc-300">
                                    {{ $update->sent_at->timezone(config('soloboard.timezone'))->format('d/m/Y H:i') }}
                                </span>
                            </div>
                            <pre class="mt-2 max-h-48 overflow-y-auto whitespace-pre-wrap text-xs text-zinc-400">{{ $update->content }}</pre>
                        </div>
                    @empty
                        <flux:text class="mt-3 block text-sm text-zinc-500">
                            Nenhum update enviado ainda para este cliente.
                        </flux:text>
                    @endforelse
                </div>
            @endif
        </div>
    </div>

    {{-- Confirmação de descarte: só aparece quando há edição manual a perder --}}
    <flux:modal wire:model.self="showRegenerateConfirm" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Regerar o rascunho?</flux:heading>
                <flux:text class="mt-1">
                    Este rascunho foi editado à mão. Regerar monta o texto de novo a partir do quadro e descarta a sua edição.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="regenerate" data-test="confirm-regenerate">
                    Regerar
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
