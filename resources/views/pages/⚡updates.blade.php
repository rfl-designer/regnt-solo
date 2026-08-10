<?php

use App\Models\Client;
use App\Models\ClientUpdate;
use App\Services\AiAssistantService;
use App\Services\ClientUpdateQueueEntry;
use App\Services\ClientUpdateService;
use App\Exceptions\UpdateAlreadySentException;
use App\Exceptions\UpdateDraftHasManualEditsException;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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

    /**
     * O id do rascunho cujo descarte o usuário confirmou.
     *
     * `#[Locked]` porque é ele, e não o modal, que autoriza o `force`: uma
     * propriedade pública comum pode ser mudada pelo browser, e um
     * `showRegenerateConfirm = true` forjado bastaria para apagar texto
     * humano sem que ninguém tivesse confirmado nada. Guardar o *id* e não um
     * booleano fecha a outra ponta: confirmar o descarte de um rascunho não
     * autoriza o descarte do rascunho seguinte.
     */
    #[Locked]
    public ?int $regenerateConfirmedFor = null;

    /**
     * O texto que a AI devolveu, esperando Aplicar ou Descartar.
     *
     * `#[Locked]` porque é ele que "Aplicar" joga por cima do editor: o
     * preview é o que a API respondeu, não o que o browser mandar.
     */
    #[Locked]
    public string $aiRefinement = '';

    /**
     * A que rascunho o preview pertence: o id e o hash do texto que foi
     * mandado para a AI.
     *
     * Um refinamento só vale para o rascunho que o gerou, do jeito que ele
     * estava. Entre pedir e clicar em Aplicar, o rascunho pode ter sido
     * enviado e recriado, ou editado noutra aba (ou por uma tool de MCP) — e
     * aplicar um texto velho por cima disso apagaria trabalho de alguém. Os
     * dois valores são `#[Locked]` pela mesma razão que `$aiRefinement`: são
     * eles que autorizam a escrita, e o browser não os alcança.
     */
    #[Locked]
    public ?int $aiRefinementFor = null;

    #[Locked]
    public string $aiRefinementSourceHash = '';

    public bool $showAiRefinement = false;

    /**
     * Quantos envios o histórico mostra. Cresce só por `loadMoreHistory()` —
     * travada para que o cliente não peça dez mil de uma vez.
     */
    #[Locked]
    public int $historyLimit = 12;

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
            : app(ClientUpdateService::class)->history($client, $this->historyLimit);
    }

    /**
     * Quantos envios ainda não estão na tela.
     */
    #[Computed]
    public function historyRemaining(): int
    {
        $client = $this->selected;

        return $client === null
            ? 0
            : max(0, app(ClientUpdateService::class)->sentCount($client) - $this->history->count());
    }

    public function loadMoreHistory(): void
    {
        $this->historyLimit += 12;

        unset($this->history, $this->historyRemaining);
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
     * Gera o rascunho. O botão só aparece quando não há nenhum aberto, mas o
     * método não conta com isso: nada aqui passa `force`, então uma chamada
     * direta a `generate` esbarra na mesma recusa do serviço e vira a mesma
     * pergunta na tela.
     */
    public function generate(): void
    {
        $this->composeDraft(force: false, heading: 'Rascunho gerado', text: 'Do estado real do quadro.');
    }

    /**
     * Pede confirmação antes de descartar edição manual; sem edição, regera
     * direto — não há nada a perder.
     */
    public function requestRegenerate(): void
    {
        $this->composeDraft(force: false, heading: 'Rascunho regerado', text: 'Do estado real do quadro.');
    }

    /**
     * O "Regerar" do modal.
     *
     * Só descarta o que o usuário confirmou: o `force` sai do id guardado em
     * {@see $regenerateConfirmedFor}, e não do modal estar aberto. Chamado
     * direto, sem confirmação, este método se comporta como
     * {@see requestRegenerate()} — pergunta em vez de apagar.
     */
    public function regenerate(): void
    {
        $confirmed = $this->regenerateConfirmedFor !== null
            && $this->regenerateConfirmedFor === $this->draft?->id;

        $this->composeDraft(
            force: $confirmed,
            heading: 'Rascunho regerado',
            text: $confirmed ? 'A edição anterior foi descartada.' : 'Do estado real do quadro.',
        );
    }

    /**
     * O caminho único de escrita do rascunho gerado — e o único lugar da
     * página que decide o que fazer com uma recusa do serviço.
     */
    private function composeDraft(bool $force, string $heading, string $text): void
    {
        $client = $this->selected;

        if ($client === null) {
            return;
        }

        // O texto na tela pode estar à frente do banco (o blur ainda não
        // aconteceu), e é ele que o usuário arriscaria perder.
        $this->persistDraft();

        try {
            $draft = app(ClientUpdateService::class)->generate($client, force: $force);
        } catch (UpdateDraftHasManualEditsException) {
            $this->regenerateConfirmedFor = $this->draft?->id;
            $this->showRegenerateConfirm = true;

            return;
        }

        $this->content = $draft->content;
        $this->showRegenerateConfirm = false;
        $this->regenerateConfirmedFor = null;
        $this->refreshClientState();

        Flux::toast(variant: 'success', heading: $heading, text: $text);
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
     * A AI é opcional: sem a flag e sem chave, a página inteira é a mesma —
     * o fluxo determinístico não depende dela em nenhum ponto.
     */
    public function isAiEnabled(): bool
    {
        return app(AiAssistantService::class)->isEnabled();
    }

    /**
     * Refina o rascunho com a AI — e não escreve nada no editor.
     *
     * O resultado vai para o preview, que é onde o humano decide. É a mesma
     * separação de "Copiar" e "Marcar como enviado": a máquina propõe o
     * texto, quem troca o rascunho é o clique em Aplicar.
     */
    public function refineWithAi(): void
    {
        if (! $this->isAiEnabled() || $this->draft === null) {
            return;
        }

        // O texto na tela pode estar à frente do banco (o blur ainda não
        // aconteceu), e é ele — com as edições à mão — que a AI revisa.
        $this->persistDraft();

        if (trim($this->content) === '') {
            return;
        }

        // `add` e não `has` + `put`: a janela é tomada num passo só, antes da
        // chamada. Checar e depois gravar deixa dois cliques simultâneos
        // passarem pela porta juntos. O preço é que uma falha da API também
        // consome o minuto — o pedido saiu, e é o pedido que se limita.
        $cacheKey = 'ai_refine_update_'.(auth()->id() ?? 'guest');

        if (! Cache::add($cacheKey, true, now()->addMinute())) {
            Flux::toast(variant: 'warning', heading: 'Aguarde', text: 'Aguarde 1 minuto antes de pedir outro refinamento.');

            return;
        }

        try {
            $refined = app(AiAssistantService::class)->refineUpdate($this->content);
        } catch (\Throwable) {
            $refined = '';
        }

        if ($refined === '') {
            Flux::toast(
                variant: 'danger',
                heading: 'Não foi possível refinar',
                text: 'O rascunho continua exatamente como estava. Tente de novo em instantes.',
            );

            return;
        }

        $this->aiRefinement = $refined;
        $this->aiRefinementFor = $this->draft?->id;
        $this->aiRefinementSourceHash = $this->hashDraftContent($this->content);
        $this->showAiRefinement = true;
    }

    /**
     * Troca o rascunho pelo texto refinado. O autosave segue daqui: o
     * conteúdo é gravado na hora, como qualquer edição.
     *
     * Antes de escrever, confere se o rascunho ainda é o que foi refinado —
     * mesmo id, mesmo texto. Se mudou, o preview é passado: descarta e avisa,
     * em vez de sobrescrever o que estiver lá agora.
     */
    public function applyAiRefinement(): void
    {
        if ($this->aiRefinement === '') {
            return;
        }

        $draft = $this->draft;

        if ($draft === null
            || $draft->id !== $this->aiRefinementFor
            || $this->hashDraftContent($draft->content) !== $this->aiRefinementSourceHash
        ) {
            $this->dismissAiRefinement();

            Flux::toast(
                variant: 'warning',
                heading: 'Refinamento descartado',
                text: 'O rascunho mudou depois do refinamento. Peça outro para não escrever por cima do texto atual.',
            );

            return;
        }

        $this->content = $this->aiRefinement;
        $this->persistDraft();

        $this->dismissAiRefinement();

        Flux::toast(variant: 'success', heading: 'Refinamento aplicado', text: 'O editor já está com o texto novo.');
    }

    /**
     * Descartar não toca em nada — o preview some e o rascunho fica.
     */
    public function discardAiRefinement(): void
    {
        $this->dismissAiRefinement();
    }

    private function dismissAiRefinement(): void
    {
        $this->aiRefinement = '';
        $this->aiRefinementFor = null;
        $this->aiRefinementSourceHash = '';
        $this->showAiRefinement = false;
    }

    private function hashDraftContent(string $content): string
    {
        return hash('sha256', $content);
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
        unset($this->selected, $this->draft, $this->history, $this->historyRemaining, $this->windowStart);

        $this->showRegenerateConfirm = false;
        $this->regenerateConfirmedFor = null;
        $this->historyLimit = 12;
        $this->content = $this->draft?->content ?? '';

        // Um preview pertence ao rascunho que o gerou: trocar de cliente o
        // descarta em vez de oferecê-lo por engano no rascunho do outro.
        $this->dismissAiRefinement();
    }

    private function refreshClientState(): void
    {
        unset($this->queue, $this->draft, $this->history, $this->historyRemaining, $this->windowStart);
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
    {{-- Os dois atalhos valem também de dentro do editor, ao contrário dos
         globais do layout: é lá que a mão está quando se quer regerar ou
         copiar. "Marcar como enviado" fica de fora de propósito — é o ato que
         carimba o histórico e zera o relógio da cadência, e ele merece um
         clique consciente em vez de um atalho ao alcance de um engano. --}}
    x-on:keydown.window="
        if (!$event.ctrlKey && !$event.metaKey) return;

        const key = $event.key.toLowerCase();

        if (key === 'g') { $event.preventDefault(); $wire.{{ $this->draft ? 'requestRegenerate' : 'generate' }}(); }
        if (key === 'enter') { $event.preventDefault(); $wire.copy(); }
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

                        {{-- O chip diz *o que* aconteceu; a categoria "Evento"
                             só diz que alguma coisa aconteceu. Sem notificação
                             ativa de propósito (issue #150): o gatilho espera
                             na fila em vez de interromper. --}}
                        @foreach ($entry->triggers as $trigger)
                            <flux:badge
                                wire:key="queue-trigger-{{ $entry->client->id }}-{{ $trigger->value }}"
                                size="sm"
                                :color="$trigger->color()"
                                :icon="$trigger->icon()"
                                :title="$trigger->reason()"
                                data-test="queue-trigger-{{ $trigger->value }}"
                            >
                                {{ $trigger->label() }}
                            </flux:badge>
                        @endforeach
                    </div>

                    <div class="mt-2 text-xs text-zinc-500">
                        {{ $entry->urgencyPhrase() }} &middot; último envio {{ $entry->lastSentPhrase() }}
                    </div>
                </button>
            @empty
                <div class="py-8 text-center">
                    <flux:icon name="building-office-2" class="mx-auto mb-2 size-8 text-zinc-600" />
                    <flux:text class="text-sm">Nenhum cliente ativo.</flux:text>
                    <flux:text class="mt-1 text-xs text-zinc-500">A fila é feita de quem tem cadência de update.</flux:text>
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
                        @if ($this->queue->isNotEmpty())
                            <flux:button
                                wire:click="select('{{ $this->queue->first()->client->slug }}')"
                                size="sm"
                                variant="primary"
                                icon="arrow-right"
                                class="mt-4"
                                data-test="select-first-client"
                            >
                                Começar por {{ $this->queue->first()->client->name }}
                            </flux:button>
                            <flux:text class="mt-3 text-xs text-zinc-500">
                                Depois, <flux:badge size="sm" color="zinc">Ctrl+G</flux:badge> gera o rascunho.
                            </flux:text>
                        @endif
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
                                {{-- A AI entra aqui como revisora de texto, e
                                     só com a flag ligada: sem ela, esta barra
                                     é a mesma de antes e o fluxo
                                     determinístico continua inteiro. --}}
                                @if ($this->isAiEnabled())
                                    <flux:button
                                        wire:click="refineWithAi"
                                        wire:loading.attr="disabled"
                                        wire:target="refineWithAi"
                                        size="sm"
                                        variant="ghost"
                                        icon="sparkles"
                                        data-test="refine-update-with-ai"
                                    >
                                        <span wire:loading.remove wire:target="refineWithAi">Refinar com AI</span>
                                        <span wire:loading wire:target="refineWithAi">Refinando...</span>
                                    </flux:button>
                                @endif
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
                                Salva sozinho ao sair do campo. Copiar (<flux:badge size="sm" color="zinc">Ctrl+Enter</flux:badge>)
                                não marca como enviado; regerar é <flux:badge size="sm" color="zinc">Ctrl+G</flux:badge>.
                            </flux:text>
                        @else
                            <div class="flex items-center justify-center rounded-lg border border-dashed border-zinc-700 bg-zinc-800/30 p-8 text-center">
                                <div>
                                    <flux:icon name="document-text" class="mx-auto mb-2 size-8 text-zinc-600" />
                                    <flux:text class="text-sm">Nenhum rascunho aberto para este cliente.</flux:text>
                                    <flux:text class="mt-1 text-xs text-zinc-500">
                                        Gerar monta os quatro blocos do estado real do quadro.
                                    </flux:text>
                                    <flux:button wire:click="generate" size="sm" variant="primary" icon="sparkles" class="mt-3">
                                        Gerar update
                                    </flux:button>
                                    <flux:text class="mt-2 text-xs text-zinc-500">
                                        Ou <flux:badge size="sm" color="zinc">Ctrl+G</flux:badge>.
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
                        <div class="mt-3 rounded-lg border border-dashed border-zinc-700 bg-zinc-800/30 p-6 text-center">
                            <flux:icon name="inbox" class="mx-auto mb-2 size-8 text-zinc-600" />
                            <flux:text class="text-sm">Nenhum update enviado ainda para este cliente.</flux:text>
                            <flux:text class="mt-1 text-xs text-zinc-500">
                                O histórico é escrito por "Marcar como enviado" — copiar não entra aqui.
                            </flux:text>
                        </div>
                    @endforelse

                    @if ($this->historyRemaining > 0)
                        <flux:button
                            wire:click="loadMoreHistory"
                            size="xs"
                            variant="ghost"
                            icon="arrow-down"
                            class="mt-3 w-full"
                            data-test="load-more-history"
                        >
                            Carregar mais {{ min($this->historyRemaining, 12) }} de {{ $this->historyRemaining }}
                        </flux:button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Preview do refinamento: a AI propõe, o editor só muda no Aplicar --}}
    @if ($this->isAiEnabled())
        <flux:modal wire:model.self="showAiRefinement" class="md:w-[38rem]">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Refinado com AI</flux:heading>
                    <flux:text class="mt-1">
                        Mesmos itens, mesmas datas, mesma ordem — mudou só o jeito de dizer. Nada foi escrito no editor ainda.
                    </flux:text>
                </div>

                <pre
                    class="max-h-96 overflow-y-auto whitespace-pre-wrap rounded-lg border border-zinc-700 bg-zinc-800 p-3 font-mono text-xs text-zinc-300"
                    data-test="ai-refinement-preview"
                >{{ $aiRefinement }}</pre>

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="discardAiRefinement" data-test="discard-ai-refinement">
                        Descartar
                    </flux:button>
                    <flux:button variant="primary" wire:click="applyAiRefinement" data-test="apply-ai-refinement">
                        Aplicar
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

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
