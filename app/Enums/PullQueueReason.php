<?php

namespace App\Enums;

use App\Services\PullQueueService;

/**
 * Why an item sits where it sits in the pull queue (issue #144).
 *
 * The three cases *are* the three degraus of the queue, in order — the
 * ordering in {@see PullQueueService} ranks by this enum's
 * {@see rank()} before anything else, so adding a degrau means adding a
 * case here rather than editing a comparator somewhere.
 */
enum PullQueueReason: string
{
    case Emergency = 'emergency';
    case FixedDateAtRisk = 'fixed_date_at_risk';
    case Fifo = 'fifo';

    /**
     * The degrau's position in the queue: lower comes first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Emergency => 0,
            self::FixedDateAtRisk => 1,
            self::Fifo => 2,
        };
    }

    /**
     * The PT-BR heading shown on the divider that opens this degrau.
     */
    public function label(): string
    {
        return match ($this) {
            self::Emergency => 'Emergência',
            self::FixedDateAtRisk => 'Data fixa em risco',
            self::Fifo => 'Ordem de chegada',
        };
    }
}
