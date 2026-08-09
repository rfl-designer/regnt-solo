<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\UpdateUrgency;
use App\Exceptions\UpdateAlreadySentException;
use App\Exceptions\UpdateDraftHasManualEditsException;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\Client;
use App\Models\ClientUpdate;
use App\Models\MorningRitual;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

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
     * A hora-alvo de quem não escolheu uma. Meio-dia é o meio do dia útil:
     * cedo o bastante para que um update da manhã não conte como atrasado,
     * tarde o bastante para não cobrar às 00:01.
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
        return Client::query()
            ->active()
            ->with(['updates' => fn ($query) => $query->orderByDesc('id')])
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client): ClientUpdateQueueEntry => $this->entryFor($client))
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
     */
    public function entryFor(Client $client): ClientUpdateQueueEntry
    {
        $lastSent = $this->lastSentAt($client);
        $scheduled = $this->lastScheduledMoment($client);

        // Se o último envio já cobriu o compromisso desta rodada, o próximo
        // é daqui a uma semana; senão, o compromisso cobrado é o que já
        // passou.
        $dueAt = $lastSent !== null && $lastSent->greaterThanOrEqualTo($scheduled)
            ? $scheduled->copy()->addWeek()
            : $scheduled;

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
        );
    }

    /**
     * O rascunho aberto do cliente, se houver.
     *
     * Lê a relação já carregada quando ela veio junto com a fila, para que
     * uma lista de clientes não faça uma consulta por linha.
     */
    public function draftFor(Client $client): ?ClientUpdate
    {
        if (! $client->relationLoaded('updates')) {
            return $client->draftUpdate();
        }

        return $client->updates
            ->filter(fn (ClientUpdate $update): bool => $update->isDraft())
            ->sortByDesc('id')
            ->first();
    }

    /**
     * O histórico: o que este cliente recebeu, do mais recente ao mais
     * antigo.
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
     * @param  bool  $force  Confirma o descarte quando o rascunho foi editado à mão.
     *
     * @throws UpdateDraftHasManualEditsException quando há edição manual e $force é falso.
     */
    public function generate(Client $client, bool $force = false): ClientUpdate
    {
        $draft = $this->draftFor($client);

        if ($draft !== null && $draft->hasManualEdits() && ! $force) {
            throw new UpdateDraftHasManualEditsException;
        }

        $content = $this->compose($client);

        if ($draft === null) {
            return $client->updates()->create([
                'content' => $content,
                'generated_content' => $content,
            ]);
        }

        $draft->update(['content' => $content, 'generated_content' => $content]);

        return $draft->refresh();
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
     * O estado vem da etapa em que a Spec está *agora*, não da última
     * movimentação: uma entrega que foi validada depois conta como validada,
     * e uma que voltou a ser trabalho em andamento sai deste bloco e entra
     * no próximo — dizer "entregue" de algo que voltou para a bancada seria
     * a única mentira que este gerador poderia contar.
     *
     * @param  EloquentCollection<int, Activity>  $specs
     * @return list<array{id: int, title: string, detail: string|null}>
     */
    private function deliveredItems(EloquentCollection $specs, CarbonInterface $windowStart): array
    {
        return $specs
            ->filter(function (Activity $spec) use ($windowStart): bool {
                if (! in_array($spec->specStage(), ['entregue', 'validada'], true)) {
                    return false;
                }

                return $spec->statusChanges->contains(
                    fn (ActivityStatusChange $change): bool => in_array(
                        $change->to_status,
                        [ActivityStatus::AwaitingValidation, ActivityStatus::Done],
                        true,
                    ) && $change->changed_at->greaterThanOrEqualTo($windowStart)
                );
            })
            ->map(fn (Activity $spec): array => [
                'id' => $spec->id,
                'title' => $spec->title,
                'detail' => $spec->specStage() === 'validada'
                    ? 'validada ✓'
                    : 'entregue, aguardando sua validação',
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
        $sentAt = $client->relationLoaded('updates')
            ? $client->updates
                ->filter(fn (ClientUpdate $update): bool => ! $update->isDraft())
                ->sortByDesc('sent_at')
                ->first()?->sent_at
            : $client->lastSentUpdate()?->sent_at;

        return $sentAt?->copy()->setTimezone($this->timezone());
    }

    /**
     * O momento de cadência mais recente que já passou: a última ocorrência
     * do dia da semana escolhido, na hora-alvo.
     *
     * Sempre no passado (no máximo sete dias atrás), porque a pergunta que
     * ele responde é "o compromisso mais recente já venceu?" — o próximo é
     * derivado dele somando uma semana.
     */
    private function lastScheduledMoment(Client $client): CarbonInterface
    {
        $now = $this->businessNow();
        $time = $client->update_time?->format('H:i') ?? self::DEFAULT_UPDATE_TIME;

        $moment = $now->copy()->startOfDay()->setTimeFromTimeString($time);

        $daysBack = ($moment->dayOfWeekIso - $client->update_day + 7) % 7;
        $moment = $moment->subDays($daysBack);

        if ($moment->greaterThan($now)) {
            $moment = $moment->subWeek();
        }

        return $moment;
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
