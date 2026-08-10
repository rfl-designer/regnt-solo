<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\PolicyKey;
use App\Enums\PullQueueReason;
use App\Models\Activity;
use App\Models\Client;
use App\Models\PolicyVersion;
use Illuminate\Support\Collection;

/**
 * The board's policies, in one place, in three kinds (issue #154).
 *
 * The panel in the Kanban header and the `get-board-policies` MCP tool are
 * both thin readers of this service, so what a person reads on the board
 * and what an AI client reads over MCP is one answer rather than two
 * implementations that happen to agree today.
 *
 * The split matters more than the grouping:
 *
 * - {@see mechanics()} is **derived**, never written. The WIP limit, the
 *   single Emergência, the degraus of the pull queue and the risk window
 *   are read from `config/soloboard.php`, the enums and the queue service
 *   on every call. A written "o limite é 2" can go stale the day the
 *   config changes; a rendered one cannot.
 * - {@see sections()} is **written and versioned** — the three questions
 *   no measurement can answer (see {@see PolicyKey}).
 * - {@see responseAgreements()} is **read from the clients**, never copied
 *   here. The agreement lives on `Client.response_agreement` and is edited
 *   in /clients; duplicating it into a policy text is how the board would
 *   end up promising two different response times.
 */
class BoardPolicyService
{
    public function __construct(
        private readonly PullQueueService $queue,
        private readonly EmergencySlotService $emergencySlot,
    ) {}

    /**
     * The mechanical policies, rendered from the state that enforces them.
     *
     * @return list<array{key: string, title: string, statement: string, detail: string, current: string|null}>
     */
    public function mechanics(): array
    {
        return [
            $this->wipLimit(),
            $this->singleEmergency(),
            $this->pullOrder(),
            $this->riskWindow(),
        ];
    }

    /**
     * "Fazendo aceita no máximo N" — plus how full it is right now, which
     * is what makes the statement checkable instead of decorative.
     *
     * @return array{key: string, title: string, statement: string, detail: string, current: string|null}
     */
    private function wipLimit(): array
    {
        $limit = (int) config('soloboard.wip_limit_doing', 2);
        $count = Activity::query()->leaf()->where('status', ActivityStatus::Doing)->count();

        return [
            'key' => 'wip_limit_doing',
            'title' => 'Limite de Fazendo',
            'statement' => "No máximo {$limit} ".($limit === 1 ? 'item' : 'itens').' em Fazendo ao mesmo tempo.',
            'detail' => 'Recusado no próprio model, então vale para toda origem: Kanban, modal, Command Palette e MCP. A Emergência é a única exceção documentada — por isso o quadro pode legitimamente marcar mais que o limite.',
            'current' => "{$count}/{$limit} agora",
        ];
    }

    /**
     * "Uma Emergência ativa por vez" — plus who is holding the slot.
     *
     * @return array{key: string, title: string, statement: string, detail: string, current: string|null}
     */
    private function singleEmergency(): array
    {
        $active = $this->emergencySlot->active();

        return [
            'key' => 'single_emergency',
            'title' => 'Emergência única',
            'statement' => 'Uma Emergência ativa por vez, sempre com motivo escrito.',
            'detail' => 'Classificar uma segunda Emergência exige decidir qual das duas continua — a troca acontece numa transação só, então o quadro nunca fica sem nenhuma porque a segunda escrita falhou.',
            'current' => $active === null
                ? 'Nenhuma Emergência ativa'
                : "Ativa: {$active->title}",
        ];
    }

    /**
     * The degraus of the pull queue, in the order the queue itself ranks
     * them — read from {@see PullQueueReason::rank()} rather than typed
     * out here, so a fourth degrau shows up in the panel by existing.
     *
     * @return array{key: string, title: string, statement: string, detail: string, current: string|null}
     */
    private function pullOrder(): array
    {
        $degraus = collect(PullQueueReason::cases())
            ->sortBy(fn (PullQueueReason $reason): int => $reason->rank())
            ->values()
            ->map(fn (PullQueueReason $reason, int $index): string => ($index + 1).'. '.$reason->label())
            ->implode('  ');

        return [
            'key' => 'pull_order',
            'title' => 'Ordem de puxar',
            'statement' => $degraus,
            'detail' => 'Data fixa só sobe quando está em risco; todo o resto entra num FIFO único pela última entrada em Pronto. Um item que voltou de Fazendo volta para o fim da fila.',
            'current' => null,
        ];
    }

    /**
     * The risk window, plus where the number came from — the measured SLE
     * once the baseline is big enough, the configured guess until then.
     *
     * @return array{key: string, title: string, statement: string, detail: string, current: string|null}
     */
    private function riskWindow(): array
    {
        $days = $this->queue->riskWindowDays();
        $fromSle = $this->queue->riskWindowFromSle();

        return [
            'key' => 'risk_window',
            'title' => 'Janela de risco',
            'statement' => "Uma Data fixa está em risco a {$days} ".($days === 1 ? 'dia' : 'dias').' do prazo ou menos (atrasada também está).',
            'detail' => $fromSle
                ? 'A janela é a SLE medida: o quadro já tem amostra suficiente para dizer em quanto tempo termina, e usa o próprio número em vez do palpite configurado.'
                : 'Enquanto a amostra da SLE for pequena, a janela é o N configurado em soloboard.fixed_date_risk_days — a aproximação honesta vale mais que um percentil sem amostra.',
            'current' => $fromSle ? 'Fonte: SLE medida' : 'Fonte: config',
        ];
    }

    /**
     * The three written policies, each with the version in force and the
     * size of its history.
     *
     * @return list<array{key: PolicyKey, label: string, hint: string, icon: string, version: PolicyVersion|null, versions_count: int}>
     */
    public function sections(): array
    {
        $counts = PolicyVersion::query()
            ->get(['id', 'key'])
            ->countBy(fn (PolicyVersion $version): string => $version->key->value);

        return collect(PolicyKey::cases())
            ->map(fn (PolicyKey $key): array => [
                'key' => $key,
                'label' => $key->label(),
                'hint' => $key->hint(),
                'icon' => $key->icon(),
                'version' => PolicyVersion::current($key),
                'versions_count' => (int) ($counts[$key->value] ?? 0),
            ])
            ->all();
    }

    /**
     * The response agreements of the active clients, read-only.
     *
     * @return Collection<int, Client>
     */
    public function responseAgreements(): Collection
    {
        return Client::query()
            ->active()
            ->whereNotNull('response_agreement')
            ->where('response_agreement', '!=', '')
            ->orderBy('name')
            ->get();
    }

    /**
     * The active clients with no agreement written — the discreet nudge.
     *
     * @return Collection<int, Client>
     */
    public function clientsWithoutAgreement(): Collection
    {
        return Client::query()
            ->active()
            ->where(fn ($query) => $query->whereNull('response_agreement')->orWhere('response_agreement', ''))
            ->orderBy('name')
            ->get();
    }
}
