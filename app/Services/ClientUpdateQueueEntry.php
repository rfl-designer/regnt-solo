<?php

namespace App\Services;

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
 */
final readonly class ClientUpdateQueueEntry
{
    /**
     * @param  Client  $client  O cliente ativo.
     * @param  UpdateUrgency  $urgency  O degrau em que ele caiu.
     * @param  CarbonInterface  $dueAt  O momento da cadência que está sendo cobrado (passado quando devido, futuro quando em dia), no fuso de negócio.
     * @param  CarbonInterface|null  $lastSentAt  Quando o último update foi enviado; null quando nenhum foi.
     * @param  ClientUpdate|null  $draft  O rascunho aberto, se houver.
     */
    public function __construct(
        public Client $client,
        public UpdateUrgency $urgency,
        public CarbonInterface $dueAt,
        public ?CarbonInterface $lastSentAt = null,
        public ?ClientUpdate $draft = null,
    ) {}

    /**
     * Dias inteiros de atraso — 0 quando não está atrasado.
     */
    public function daysLate(): int
    {
        if ($this->urgency !== UpdateUrgency::Overdue) {
            return 0;
        }

        return (int) $this->dueAt->copy()->startOfDay()->diffInDays($this->today());
    }

    /**
     * Dias inteiros até o próximo update — 0 quando é hoje ou já passou.
     */
    public function daysUntil(): int
    {
        if ($this->urgency !== UpdateUrgency::OnTrack) {
            return 0;
        }

        return (int) $this->today()->diffInDays($this->dueAt->copy()->startOfDay());
    }

    /**
     * A frase que a fila mostra ao lado do nome do cliente.
     */
    public function urgencyPhrase(): string
    {
        return match ($this->urgency) {
            UpdateUrgency::Overdue => 'atrasado há '.$this->plural($this->daysLate()),
            UpdateUrgency::DueToday => 'vence hoje',
            UpdateUrgency::OnTrack => $this->daysUntil() === 0
                ? 'em dia'
                : 'em '.$this->plural($this->daysUntil()),
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
     * A chave de ordenação: degrau primeiro, depois o momento cobrado (o
     * mais antigo antes, porque atraso maior dói mais), e o id como
     * desempate para que a ordem seja total.
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
