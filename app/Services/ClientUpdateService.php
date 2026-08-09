<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Enums\UpdateTrigger;
use App\Enums\UpdateUrgency;
use App\Exceptions\UpdateAlreadySentException;
use App\Exceptions\UpdateDraftHasManualEditsException;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\Client;
use App\Models\ClientUpdate;
use App\Models\MorningRitual;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O update semanal por cliente: quem está devido, o que o texto diz, e o
 * ato de enviá-lo (issue #149).
 *
 * Um lugar computa; a página Updates, a badge da sidebar e as três tools de
 * MCP consomem. Nenhuma dessas superfícies ordena a fila, calcula a janela
 * ou monta um bloco por conta própria — é o que garante que o rascunho que
 * um agente gera por MCP seja byte a byte o rascunho que o botão gera.
 *
 * ## A janela
 *
 * Um update cobre desde o último envio, **sem teto**. O primeiro cobre 7
 * dias, porque não há de onde começar. Um cliente que ficou três semanas
 * sem notícias recebe um update de três semanas: o atraso aparece como
 * atraso em vez de ser truncado numa semana bonitinha.
 *
 * ## Os quatro blocos
 *
 * Tudo filtrado por cliente efetivo e por {@see Activity::scopeSpecLevel()},
 * com bloco vazio omitido:
 *
 * 1. **Entregue** — o que entrou em Aguardando validação ou Feito na
 *    janela, com o estado em que ficou.
 * 2. **Em andamento** — o que foi aprovado e ainda não foi entregue, com a
 *    frase do hill quando ela existe.
 * 3. **Esperando você** — o que está *agora* na mão do cliente, com desde
 *    quando. A redundância com o bloco 1 é de propósito: um conta a
 *    conquista, o outro cobra a ação.
 * 4. **Próximo** — o que a fila de puxar indica, com a previsão da SLE
 *    quando ela é utilizável.
 *
 * As datas dos blocos 1 e 2 são as do ciclo de vida da Spec (issue #146),
 * derivadas do histórico de status. Não há coluna de "entregue em" para
 * divergir do quadro.
 *
 * ## Os gatilhos por evento
 *
 * A cadência não é o único jeito de um cliente entrar na fila: uma entrega
 * esperando validação e uma Emergência são notícias que não esperam a terça
 * (issue #150). Elas promovem o cliente para {@see UpdateUrgency::Event},
 * acima de "atrasado", e contam na badge. Como tudo aqui, são derivadas —
 * veja {@see triggersFor()} para o que dispara, por que a janela também
 * limita a Emergência ativa, e qual furo isso aceita de propósito.
 */
class ClientUpdateService
{
    /**
     * Quantos dias o primeiro update de um cliente cobre. Depois disso a
     * janela é sempre "desde o último envio".
     */
    public const int FIRST_WINDOW_DAYS = 7;

    /**
     * Quantas specs o bloco "Próximo" nomeia.
     *
     * A fila de puxar inteira não é uma promessa — é a ordem em que as
     * coisas serão puxadas, e listá-la toda transformaria o update num
     * compromisso com o backlog. Três é o que cabe numa mensagem que alguém
     * lê no WhatsApp.
     */
    public const int NEXT_LIMIT = 3;

    /**
     * A hora-alvo de quem não escolheu uma.
     *
     * A hora **não decide** se o cliente está devido — isso é uma pergunta de
     * dia (veja {@see entryFor()}). Ela é o instante que a fila mostra e que
     * o MCP publica em `due_at`, e é o que os gatilhos por evento da issue
     * #150 vão agendar. Meio-dia é o meio do dia útil: um horário que se lê
     * como "hoje" sem sugerir madrugada nem fim de expediente.
     */
    private const string DEFAULT_UPDATE_TIME = '12:00';

    public function __construct(
        private readonly PullQueueService $pullQueue,
        private readonly FlowMetricsService $metrics,
    ) {}

    /**
     * A fila de clientes ativos, em ordem de urgência.
     *
     * @return Collection<int, ClientUpdateQueueEntry>
     */
    public function queue(): Collection
    {
        $clients = Client::query()
            ->active()
            // Duas linhas por cliente, não o histórico inteiro: a badge da
            // sidebar roda esta mesma fila em toda página.
            ->with([
                // As colunas vão qualificadas porque `latestOfMany()` junta a
                // tabela com ela mesma.
                'latestSentUpdate:client_updates.id,client_updates.client_id,client_updates.sent_at',
                'currentDraft',
            ])
            ->orderBy('name')
            ->get();

        // Os gatilhos saem de uma varredura só para a fila inteira — uma
        // consulta, com ou sem candidatos. Perguntar por cliente faria a
        // badge da sidebar custar uma consulta por cliente em toda página.
        $triggers = $this->triggersFor($clients);

        return $clients
            ->map(fn (Client $client): ClientUpdateQueueEntry => $this->entryFor(
                $client,
                $triggers[$client->getKey()] ?? [],
            ))
            ->sort(fn (ClientUpdateQueueEntry $a, ClientUpdateQueueEntry $b): int => $a->sortKey() <=> $b->sortKey())
            ->values();
    }

    /**
     * Quantos updates estão devidos agora — o número da badge na sidebar.
     */
    public function dueCount(): int
    {
        return $this->queue()->filter(fn (ClientUpdateQueueEntry $entry): bool => $entry->urgency->isDue())->count();
    }

    /**
     * A posição de um cliente na fila, calculada da cadência contra o
     * último envio.
     *
     * **A urgência é uma pergunta de dia.** "Toda terça" é um compromisso com
     * a terça, não com as 09:00 de terça: às 08:00 o cliente vence hoje, às
     * 18:00 ele continua vencendo hoje, e só na quarta ele está atrasado — de
     * um dia. Classificar pelo instante faria o mesmo cliente pular de "em
     * dia" para "atrasado" no meio do expediente, e faria um cliente sem
     * nenhum envio aparecer sete dias atrasado às 08:00 da própria terça,
     * porque a ocorrência mais recente *já vencida* ainda seria a da semana
     * anterior.
     *
     * A hora-alvo entra em dois lugares, e só neles: no instante que a fila
     * mostra ({@see ClientUpdateQueueEntry::$dueAt}) e no desempate de um
     * envio feito no próprio dia do compromisso — enviar terça às 08:00 cobre
     * a terça, mesmo que a hora-alvo fosse 09:00.
     *
     * A cadência é só metade da resposta: os gatilhos por evento (issue #150)
     * podem promover o cliente para {@see UpdateUrgency::Event} por cima de
     * qualquer degrau do relógio. Quem já os computou em lote passa a lista
     * pronta; quem chama por um cliente só deixa o parâmetro de fora e paga a
     * varredura.
     *
     * @param  list<UpdateTrigger>|null  $triggers
     */
    public function entryFor(Client $client, ?array $triggers = null): ClientUpdateQueueEntry
    {
        $triggers ??= $this->triggersFor(new EloquentCollection([$client]))[$client->getKey()] ?? [];

        $lastSent = $this->lastSentAt($client);
        $charged = $this->chargedOccurrence($client);

        // Um envio no dia do compromisso (ou depois) cobre esse compromisso;
        // o próximo é uma semana adiante. A comparação é por dia justamente
        // porque o compromisso é o dia.
        $dueAt = $lastSent !== null && $lastSent->copy()->startOfDay()->greaterThanOrEqualTo($charged->copy()->startOfDay())
            ? $charged->copy()->addWeek()
            : $charged;

        $today = $this->businessNow()->startOfDay();
        $dueDay = $dueAt->copy()->startOfDay();

        $urgency = match (true) {
            $dueDay->lessThan($today) => UpdateUrgency::Overdue,
            $dueDay->equalTo($today) => UpdateUrgency::DueToday,
            default => UpdateUrgency::OnTrack,
        };

        return new ClientUpdateQueueEntry(
            $client,
            $urgency,
            $dueAt,
            $lastSent,
            $this->draftFor($client),
            $triggers,
        );
    }

    /**
     * Os gatilhos por evento de cada cliente, computados numa varredura só
     * (issue #150).
     *
     * ## O que dispara
     *
     * Duas coisas, ambas medidas contra a **janela** do cliente — o mesmo
     * "desde o último envio" que o texto do update cobre ({@see
     * windowStart()}), e sempre **estritamente depois** dele: um evento
     * carimbado no mesmo segundo do envio pertence ao update que acabou de
     * sair, e cobrá-lo de novo faria "enviar apaga o gatilho" falhar na
     * fronteira. As três datas envolvidas (`sent_at`, `changed_at`,
     * `emergency_since`) têm precisão de segundo, então o empate é
     * alcançável de fato.
     *
     * 1. **Entrega aguardando validação** — uma spec do cliente entrou em
     *    Aguardando validação na janela. É o evento, não o estado: uma spec
     *    entregue e já validada depois continua sendo notícia, porque a
     *    entrega aconteceu nestes dias.
     * 2. **Emergência** — um item do cliente (qualquer um, não só nível
     *    spec: uma Emergência é uma Emergência mesmo numa filha) foi
     *    classificado como Emergência na janela e ainda está ativo, **ou**
     *    foi concluído na janela tendo motivo registrado.
     *
     * ## Por que a Emergência ativa também olha a janela
     *
     * "Ativa agora" sozinho manteria o cliente em "evento" para sempre
     * enquanto o fogo durasse — inclusive depois de um update que já falou
     * dela. Datar pelo `emergency_since` mantém a promessa que vale para os
     * dois gatilhos: **enviar apaga o gatilho**, porque enviar abre uma
     * janela nova. Uma Emergência aberta há duas semanas e ainda ativa já foi
     * contada; se ela for concluída, a conclusão é notícia de novo.
     *
     * ## Nada é marcado como lido
     *
     * Não há coluna, tabela nem flag de "gatilho disparado": a pergunta é
     * refeita a cada leitura da fila. Daí decorre o furo aceito e documentado
     * na issue #150 — uma Emergência **rebaixada** antes do envio some do
     * radar, porque rebaixar apaga `service_class`, `emergency_reason` e
     * `emergency_since`, e não sobra nada no estado que prove que ela
     * existiu. O preço de fechar esse furo seria persistência nova, que é
     * exatamente o que esta feature se recusa a ter.
     *
     * @param  EloquentCollection<int, Client>  $clients
     * @return array<int, list<UpdateTrigger>>
     */
    public function triggersFor(EloquentCollection $clients): array
    {
        if ($clients->isEmpty()) {
            return [];
        }

        /** @var array<int, CarbonInterface> $windows */
        $windows = $clients
            ->mapWithKeys(fn (Client $client): array => [$client->getKey() => $this->windowStart($client)])
            ->all();

        // A janela mais antiga é o piso da varredura: um evento anterior a
        // ela não interessa a nenhum cliente, e filtrar por ela no banco
        // evita hidratar o histórico inteiro do quadro.
        $earliest = collect($windows)->reduce(
            fn (?CarbonInterface $carry, CarbonInterface $start): CarbonInterface => $carry === null || $start->lessThan($carry) ? $start : $carry,
        );

        $found = [];

        foreach ($this->triggerCandidates($earliest) as $activity) {
            $clientId = $activity->effective_client_id ?? $activity->client_id;

            if ($clientId === null || ! isset($windows[$clientId])) {
                continue;
            }

            foreach ($this->triggersOf($activity, $windows[$clientId]) as $trigger) {
                $found[$clientId][] = $trigger;
            }
        }

        return array_map(UpdateTrigger::order(...), $found);
    }

    /**
     * Os itens que podem ter disparado alguma coisa desde `$earliest`, numa
     * consulta só.
     *
     * **Uma consulta é o requisito, não um detalhe**: a badge da sidebar
     * chama isto em toda página carregada. Por isso nada de relação
     * carregada — o cliente efetivo e as duas datas que decidem os gatilhos
     * vêm como colunas calculadas, em subconsultas correlacionadas. Hidratar
     * `statusChanges` e `project` custaria mais duas consultas exatamente
     * quando há evento, que é quando a fila mais é lida.
     *
     * As três colunas:
     *
     * - `effective_client_id` — o cliente do projeto quando há projeto; a
     *   coluna direta do item quando não há. É o que
     *   {@see Activity::scopeForClient()} espelha em SQL, resolvido aqui em
     *   PHP porque a fila precisa do valor, não do filtro.
     * - `delivered_at` — a **última** entrada em Aguardando validação. O
     *   máximo basta: se a mais recente ficou fora da janela, nenhuma
     *   anterior cabe nela.
     * - `concluded_at` — idem para a entrada em Feito, que é o que data uma
     *   Emergência encerrada.
     *
     * @return EloquentCollection<int, Activity>
     */
    private function triggerCandidates(CarbonInterface $earliest): EloquentCollection
    {
        return Activity::query()
            ->select('activities.*')
            ->addSelect([
                'effective_client_id' => Project::query()
                    ->select('client_id')
                    ->whereColumn('projects.id', 'activities.project_id'),
                'delivered_at' => $this->lastChangeInto(ActivityStatus::AwaitingValidation),
                'concluded_at' => $this->lastChangeInto(ActivityStatus::Done),
            ])
            ->withCasts(['delivered_at' => 'datetime', 'concluded_at' => 'datetime'])
            ->where(function (Builder $query) use ($earliest): void {
                $query
                    ->where(fn (Builder $delivered) => $delivered
                        ->specLevel()
                        ->whereHas('statusChanges', fn (Builder $change) => $change
                            ->where('to_status', ActivityStatus::AwaitingValidation)
                            ->where('changed_at', '>', $earliest)))
                    ->orWhere(fn (Builder $emergency) => $emergency
                        ->where('service_class', ServiceClass::Emergency)
                        ->where(fn (Builder $slot) => $slot
                            ->where(fn (Builder $open) => $open
                                ->activeEmergency()
                                ->where('emergency_since', '>', $earliest))
                            ->orWhere(fn (Builder $closed) => $closed
                                ->where('status', ActivityStatus::Done)
                                ->whereNotNull('emergency_reason')
                                ->whereHas('statusChanges', fn (Builder $change) => $change
                                    ->where('to_status', ActivityStatus::Done)
                                    ->where('changed_at', '>', $earliest)))));
            })
            ->get();
    }

    /**
     * A data da última entrada de um item neste status, como subconsulta
     * correlacionada.
     */
    private function lastChangeInto(ActivityStatus $status): Builder
    {
        return ActivityStatusChange::query()
            ->selectRaw('max(changed_at)')
            ->whereColumn('activity_id', 'activities.id')
            ->where('to_status', $status);
    }

    /**
     * Os gatilhos que este item dispara para uma janela — a mesma pergunta
     * do banco, refeita contra a janela do cliente de fato.
     *
     * @return list<UpdateTrigger>
     */
    private function triggersOf(Activity $activity, CarbonInterface $windowStart): array
    {
        $triggers = [];

        if ($activity->isSpecLevel() && $this->within($activity->delivered_at, $windowStart)) {
            $triggers[] = UpdateTrigger::DeliveryAwaitingValidation;
        }

        if ($this->isEmergencyEvent($activity, $windowStart)) {
            $triggers[] = UpdateTrigger::Emergency;
        }

        return $triggers;
    }

    /**
     * Se a Emergência deste item é notícia dentro da janela: aberta nela e
     * ainda ativa, ou concluída nela com motivo.
     */
    private function isEmergencyEvent(Activity $activity, CarbonInterface $windowStart): bool
    {
        if ($activity->service_class !== ServiceClass::Emergency) {
            return false;
        }

        if ($activity->isActiveEmergency()) {
            return $this->within($activity->emergency_since, $windowStart);
        }

        // O motivo é o que faz uma Emergência concluída ser contável: sem
        // ele não há o que dizer ao cliente além de "houve um incêndio".
        return $activity->status === ActivityStatus::Done
            && $activity->emergency_reason !== null
            && $this->within($activity->concluded_at, $windowStart);
    }

    /**
     * Se um instante cai na janela: **estritamente depois** do envio que a
     * abriu. Um evento carimbado no mesmo segundo do envio já saiu no texto
     * que acabou de ir; cobrá-lo de novo deixaria o cliente em "evento" por
     * uma notícia que ele acabou de receber.
     */
    private function within(?CarbonInterface $moment, CarbonInterface $windowStart): bool
    {
        return $moment !== null && $moment->greaterThan($windowStart);
    }

    /**
     * O rascunho aberto do cliente, se houver.
     *
     * Lê a relação já carregada quando ela veio junto com a fila, para que
     * uma lista de clientes não faça uma consulta por linha.
     */
    public function draftFor(Client $client): ?ClientUpdate
    {
        return $client->relationLoaded('currentDraft')
            ? $client->currentDraft
            : $client->draftUpdate();
    }

    /**
     * O histórico: o que este cliente recebeu, do mais recente ao mais
     * antigo.
     *
     * O limite é uma página, não um teto — {@see sentCount()} diz quanto
     * ficou de fora para que a tela possa oferecer o resto. Um histórico que
     * simplesmente parasse no décimo segundo envio apagaria da vista tudo
     * que passou de três meses, que é o horizonte em que a pergunta "o que
     * eu te disse naquela época?" costuma aparecer.
     *
     * @return EloquentCollection<int, ClientUpdate>
     */
    public function history(Client $client, int $limit = 12): EloquentCollection
    {
        return $client->updates()
            ->sent()
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Quantos updates este cliente já recebeu.
     */
    public function sentCount(Client $client): int
    {
        return $client->updates()->sent()->count();
    }

    /**
     * Desde quando o próximo update cobre: o último envio, ou sete dias
     * atrás quando não houve nenhum.
     */
    public function windowStart(Client $client): CarbonInterface
    {
        return $this->lastSentAt($client)?->copy()->utc()
            ?? now()->subDays(self::FIRST_WINDOW_DAYS);
    }

    /**
     * Gera (ou regenera) o rascunho do cliente e o persiste.
     *
     * Este é o único caminho de escrita do rascunho gerado — o botão da
     * página e a tool `generate-client-update` chamam exatamente isto, o
     * que é o que faz os dois textos serem o mesmo texto.
     *
     * Roda dentro de uma transação com a linha do cliente travada: sem isso,
     * a página e um agente por MCP gerando ao mesmo tempo passariam os dois
     * pelo "não há rascunho" e abririam um cada. O índice único de
     * `draft_client_id` é a segunda linha de defesa, para o caso de o banco
     * não honrar a trava.
     *
     * @param  bool  $force  Confirma o descarte quando o rascunho foi editado à mão.
     *
     * @throws UpdateDraftHasManualEditsException quando há edição manual e $force é falso.
     */
    public function generate(Client $client, bool $force = false): ClientUpdate
    {
        return DB::transaction(function () use ($client, $force): ClientUpdate {
            // A leitura tem de ser fresca e travada: um `$client` que veio da
            // fila carrega relações já carregadas, e decidir sobre elas seria
            // decidir sobre o estado de antes da transação.
            $locked = Client::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            $draft = $locked->draftUpdate();

            if ($draft !== null && $draft->hasManualEdits() && ! $force) {
                throw new UpdateDraftHasManualEditsException;
            }

            $content = $this->compose($locked);

            if ($draft === null) {
                return $locked->updates()->create([
                    'content' => $content,
                    'generated_content' => $content,
                ]);
            }

            $draft->update(['content' => $content, 'generated_content' => $content]);

            return $draft->refresh();
        });
    }

    /**
     * Marca o rascunho como enviado: grava a data e, com ela, zera o
     * relógio da cadência e fecha a janela do próximo.
     *
     * Copiar não passa por aqui — copiar não é enviar, e é essa separação
     * que faz o histórico refletir o que o cliente de fato recebeu.
     *
     * @throws UpdateAlreadySentException quando o update já tem data de envio.
     */
    public function markSent(ClientUpdate $update): ClientUpdate
    {
        if (! $update->isDraft()) {
            throw new UpdateAlreadySentException;
        }

        $update->forceFill(['sent_at' => now()])->save();

        return $update->refresh();
    }

    /**
     * O texto do update, montado do estado real do quadro.
     *
     * Determinístico: as mesmas quatro seções, na mesma ordem, com bloco
     * vazio omitido. Markdown leve de propósito — negrito e marcadores
     * sobrevivem colados no WhatsApp, no Slack e num e-mail.
     */
    public function compose(Client $client): string
    {
        $blocks = array_filter(
            $this->blocks($client),
            fn (array $block): bool => $block['items'] !== [],
        );

        $lines = [$this->periodHeading($client), ''];

        if ($blocks === []) {
            $lines[] = 'Sem novidades por aqui desde o último update.';

            return implode("\n", $lines)."\n";
        }

        foreach ($blocks as $block) {
            $lines[] = "**{$block['title']}**";

            foreach ($block['items'] as $item) {
                $lines[] = '- '.$item['title'].($item['detail'] === null ? '' : ' — '.$item['detail']);
            }

            $lines[] = '';
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * Os quatro blocos com o que cada um encontrou, antes de virarem texto.
     *
     * A página lê isto para pré-visualizar e os testes o leem para checar
     * bloco a bloco — {@see compose()} é só a renderização desta estrutura.
     *
     * @return list<array{key: string, title: string, items: list<array{id: int, title: string, detail: string|null}>}>
     */
    public function blocks(Client $client): array
    {
        $windowStart = $this->windowStart($client);
        $specs = $this->specsFor($client);

        return [
            ['key' => 'entregue', 'title' => 'Entregue', 'items' => $this->deliveredItems($specs, $windowStart)],
            ['key' => 'em_andamento', 'title' => 'Em andamento', 'items' => $this->inProgressItems($specs)],
            ['key' => 'esperando_voce', 'title' => 'Esperando você', 'items' => $this->waitingOnClientItems($specs)],
            ['key' => 'proximo', 'title' => 'Próximo', 'items' => $this->nextItems($client)],
        ];
    }

    /**
     * O que entrou em Aguardando validação ou Feito dentro da janela.
     *
     * A seleção é pelo **evento**: a entrega aconteceu nestes dias, e isso
     * não deixa de ser verdade depois. O estado atual da Spec entra só no
     * detalhe — validada, aguardando validação, ou de volta à bancada.
     *
     * Uma entrega que foi devolvida para ajustes é o caso que decide o
     * desenho. Omiti-la seria a mentira mais fácil de contar: o cliente viu a
     * entrega, e um update que não a menciona sugere que ela nunca saiu. Ela
     * fica, dizendo o que de fato aconteceu — e reaparece em "Em andamento",
     * que é onde o trabalho está agora.
     *
     * @param  EloquentCollection<int, Activity>  $specs
     * @return list<array{id: int, title: string, detail: string|null}>
     */
    private function deliveredItems(EloquentCollection $specs, CarbonInterface $windowStart): array
    {
        return $specs
            ->filter(fn (Activity $spec): bool => $spec->statusChanges->contains(
                fn (ActivityStatusChange $change): bool => in_array(
                    $change->to_status,
                    [ActivityStatus::AwaitingValidation, ActivityStatus::Done],
                    true,
                ) && $change->changed_at->greaterThanOrEqualTo($windowStart)
            ))
            ->map(fn (Activity $spec): array => [
                'id' => $spec->id,
                'title' => $spec->title,
                'detail' => match ($spec->specStage()) {
                    'validada' => 'validada ✓',
                    'entregue' => 'entregue, aguardando sua validação',
                    default => 'entregue e devolvida para ajustes',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * O que foi aprovado e ainda não foi entregue, com a frase do hill.
     *
     * Sem contagem de filhas e sem percentual: o hill é binário justamente
     * para que "70% pronto" não vire um compromisso que ninguém mediu.
     * Quando não há posição marcada, a frase é omitida em vez de virar um
     * "em andamento" redundante com o título do bloco.
     *
     * @param  EloquentCollection<int, Activity>  $specs
     * @return list<array{id: int, title: string, detail: string|null}>
     */
    private function inProgressItems(EloquentCollection $specs): array
    {
        return $specs
            ->filter(fn (Activity $spec): bool => $spec->specStage() === 'aprovada')
            ->map(fn (Activity $spec): array => [
                'id' => $spec->id,
                'title' => $spec->title,
                'detail' => $spec->hill_position?->phrase(),
            ])
            ->values()
            ->all();
    }

    /**
     * O que está na mão do cliente agora, com desde quando.
     *
     * @param  EloquentCollection<int, Activity>  $specs
     * @return list<array{id: int, title: string, detail: string|null}>
     */
    private function waitingOnClientItems(EloquentCollection $specs): array
    {
        return $specs
            ->filter(fn (Activity $spec): bool => $spec->status?->isClientWaiting() === true)
            ->map(fn (Activity $spec): array => [
                'id' => $spec->id,
                'title' => $spec->title,
                'detail' => $this->waitingDetail($spec),
            ])
            ->values()
            ->all();
    }

    /**
     * "aguardando sua aprovação há 3 dias".
     */
    private function waitingDetail(Activity $spec): string
    {
        $what = $spec->status === ActivityStatus::AwaitingApproval
            ? 'aguardando sua aprovação'
            : 'aguardando sua validação';

        if ($spec->waiting_since === null) {
            return $what;
        }

        $days = $spec->waitingDays();

        return $days === 0
            ? $what.' desde hoje'
            : $what.' há '.$days.' '.($days === 1 ? 'dia' : 'dias');
    }

    /**
     * O que vem a seguir, lido da fila de puxar (issue #144).
     *
     * A fila range cards; o update fala specs. Cada posição é traduzida
     * para a spec a que pertence (o Épico do card, ou o próprio item quando
     * ele é avulso) e as repetições somem — cinco fatias do mesmo Épico são
     * uma linha, que é como o cliente entende o compromisso.
     *
     * A previsão só aparece quando a SLE é utilizável. Citar um percentil
     * calculado sobre seis itens seria transformar ruído em promessa.
     *
     * @return list<array{id: int, title: string, detail: string|null}>
     */
    private function nextItems(Client $client): array
    {
        $sle = $this->metrics->sleDays();

        $detail = $sle === null ? null : 'previsão de até '.$sle.' '.($sle === 1 ? 'dia' : 'dias');

        return $this->pullQueue
            ->queue(fn ($query) => $query->forClient($client))
            ->map(function (PullQueueEntry $entry): Activity {
                $activity = $entry->activity;

                return $activity->parent_id === null
                    ? $activity
                    : ($activity->relationLoaded('parent') ? $activity->parent : $activity->parent()->first()) ?? $activity;
            })
            ->unique('id')
            ->take(self::NEXT_LIMIT)
            ->map(fn (Activity $spec): array => [
                'id' => $spec->id,
                'title' => $spec->title,
                'detail' => $detail,
            ])
            ->values()
            ->all();
    }

    /**
     * As specs deste cliente, com o histórico de status carregado.
     *
     * Uma consulta e a partição em memória: as quatro perguntas são feitas
     * sobre os mesmos accessors do ciclo de vida da Spec, e traduzi-las
     * para SQL faria o update discordar do modal do Épico na primeira
     * divergência de arredondamento.
     *
     * @return EloquentCollection<int, Activity>
     */
    private function specsFor(Client $client): EloquentCollection
    {
        return Activity::query()
            ->specLevel()
            ->forClient($client)
            ->with(['statusChanges', 'project.client', 'client'])
            ->get();
    }

    /**
     * "Update — 01/08 a 08/08": o período que o texto cobre.
     *
     * Vai sempre, inclusive quando não há nada a contar — é o que faz um
     * "sem novidades" significar "nestes dias", e não "algum dia".
     */
    private function periodHeading(Client $client): string
    {
        $timezone = $this->timezone();
        $start = $this->windowStart($client)->copy()->setTimezone($timezone);
        $end = $this->businessNow();

        return sprintf('**Update — %s a %s**', $start->format('d/m'), $end->format('d/m'));
    }

    /**
     * Quando o último update foi enviado, no fuso de negócio.
     */
    private function lastSentAt(Client $client): ?CarbonInterface
    {
        $sentAt = $client->relationLoaded('latestSentUpdate')
            ? $client->latestSentUpdate?->sent_at
            : $client->lastSentUpdate()?->sent_at;

        return $sentAt?->copy()->setTimezone($this->timezone());
    }

    /**
     * A ocorrência de cadência que está sendo cobrada: o dia da semana
     * escolhido, na hora-alvo, na sua passagem mais recente.
     *
     * O **dia** nunca é futuro (é hoje, ou até seis dias atrás); a **hora**
     * pode ser — no dia do compromisso, às 08:00, o instante cobrado é hoje
     * às 09:00. É essa distinção que faz "vence hoje" começar quando o dia
     * começa, em vez de começar quando o relógio bate a hora-alvo.
     */
    private function chargedOccurrence(Client $client): CarbonInterface
    {
        $time = $client->update_time?->format('H:i') ?? self::DEFAULT_UPDATE_TIME;

        $moment = $this->businessNow()->copy()->startOfDay()->setTimeFromTimeString($time);

        return $moment->subDays(($moment->dayOfWeekIso - $client->update_day + 7) % 7);
    }

    /**
     * Agora, no fuso em que o usuário lê um relógio.
     *
     * A cadência é uma pergunta de calendário ("é terça-feira?"), e o app
     * roda em UTC. {@see MorningRitual::timezone()} é onde essa resposta
     * mora desde a issue #147 — ter uma segunda definição aqui deixaria o
     * ritual e a fila de updates discordando sobre que dia é hoje.
     */
    private function businessNow(): CarbonInterface
    {
        return MorningRitual::businessNow();
    }

    private function timezone(): string
    {
        return MorningRitual::timezone();
    }
}
