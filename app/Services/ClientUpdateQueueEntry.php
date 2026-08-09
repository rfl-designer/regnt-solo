<?php

namespace App\Services;

use App\Enums\UpdateTrigger;
use App\Enums\UpdateUrgency;
use App\Models\Client;
use App\Models\ClientUpdate;
use Carbon\CarbonInterface;

/**
 * Uma posição na fila de updates: o cliente, quão devido ele está e as duas
 * datas que explicam isso (issue #149).
 *
 * É o que a página Updates, a badge da sidebar e a tool `get-update-queue`
 * leem — nenhuma delas calcula urgência por conta própria, então a ordem
 * que a pessoa vê e a que um agente recebe são a mesma por construção.
 *
 * A categoria e a cadência são coisas separadas desde a issue #150. Um
 * gatilho por evento muda a **categoria** para {@see UpdateUrgency::Event},
 * e é ela que ordena a fila e conta na badge; a cadência continua sendo o
 * degrau do relógio, e é dela que saem "atrasado há 3 dias" e "em 4 dias".
 * Guardar as duas é o que permite um cliente ser lido como "evento" na
 * categoria e ainda dizer, na mesma linha, que ele também está atrasado.
 */
final readonly class ClientUpdateQueueEntry
{
    /**
     * A categoria da fila: {@see UpdateUrgency::Event} quando há gatilho,
     * senão o degrau da cadência.
     */
    public UpdateUrgency $urgency;

    /**
     * @param  Client  $client  O cliente ativo.
     * @param  UpdateUrgency  $cadence  O degrau do relógio — nunca `Event`.
     * @param  CarbonInterface  $dueAt  O momento da cadência que está sendo cobrado (passado quando devido, futuro quando em dia), no fuso de negócio.
     * @param  CarbonInterface|null  $lastSentAt  Quando o último update foi enviado; null quando nenhum foi.
     * @param  ClientUpdate|null  $draft  O rascunho aberto, se houver.
     * @param  list<UpdateTrigger>  $triggers  Os gatilhos por evento vivos nesta janela, na ordem dos chips.
     */
    public function __construct(
        public Client $client,
        public UpdateUrgency $cadence,
        public CarbonInterface $dueAt,
        public ?CarbonInterface $lastSentAt = null,
        public ?ClientUpdate $draft = null,
        public array $triggers = [],
    ) {
        $this->urgency = $triggers === [] ? $cadence : UpdateUrgency::Event;
    }

    /**
     * Se algum evento do quadro está pedindo um update fora da cadência.
     */
    public function hasTriggers(): bool
    {
        return $this->triggers !== [];
    }

    /**
     * Dias inteiros de atraso — 0 quando não está atrasado.
     */
    public function daysLate(): int
    {
        if ($this->cadence !== UpdateUrgency::Overdue) {
            return 0;
        }

        return (int) $this->dueAt->copy()->startOfDay()->diffInDays($this->today());
    }

    /**
     * Dias inteiros até o próximo update — 0 quando é hoje ou já passou.
     */
    public function daysUntil(): int
    {
        if ($this->cadence !== UpdateUrgency::OnTrack) {
            return 0;
        }

        return (int) $this->today()->diffInDays($this->dueAt->copy()->startOfDay());
    }

    /**
     * A frase que a fila mostra ao lado do nome do cliente.
     *
     * Fala sempre da cadência, mesmo quando a categoria é evento: o chip do
     * gatilho já diz o que aconteceu, e o que falta saber é se este cliente
     * também estava atrasado.
     */
    public function urgencyPhrase(): string
    {
        return match ($this->cadence) {
            UpdateUrgency::Overdue => 'atrasado há '.$this->plural($this->daysLate()),
            UpdateUrgency::DueToday => 'vence hoje',
            UpdateUrgency::OnTrack => $this->daysUntil() === 0
                ? 'em dia'
                : 'em '.$this->plural($this->daysUntil()),
            UpdateUrgency::Event => 'evento no quadro',
        };
    }

    /**
     * "há 9 dias" / "nunca" — quando o cliente teve notícias pela última vez.
     */
    public function lastSentPhrase(): string
    {
        if ($this->lastSentAt === null) {
            return 'nunca';
        }

        $days = (int) $this->lastSentAt->copy()->startOfDay()->diffInDays($this->today());

        return $days === 0 ? 'hoje' : 'há '.$this->plural($days);
    }

    public function hasDraft(): bool
    {
        return $this->draft !== null;
    }

    /**
     * A chave de ordenação: a categoria primeiro (evento no topo), depois o
     * momento cobrado (o mais antigo antes, porque atraso maior dói mais), e
     * o id como desempate para que a ordem seja total.
     *
     * Dentro de "evento" a cadência continua ordenando: entre dois clientes
     * com gatilho, quem está atrasado há mais tempo vem antes.
     *
     * @return array{int, int, int}
     */
    public function sortKey(): array
    {
        return [$this->urgency->rank(), $this->dueAt->getTimestamp(), $this->client->id];
    }

    /**
     * Hoje, no mesmo fuso em que a cadência foi calculada — comparar um
     * `dueAt` no fuso de negócio com um "hoje" em UTC erraria o dia inteiro
     * nas horas em que os dois calendários discordam.
     */
    private function today(): CarbonInterface
    {
        return now()->setTimezone($this->dueAt->getTimezone())->startOfDay();
    }

    private function plural(int $days): string
    {
        return $days.' '.($days === 1 ? 'dia' : 'dias');
    }
}
