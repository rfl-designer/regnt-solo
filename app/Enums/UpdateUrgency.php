<?php

namespace App\Enums;

/**
 * Quão devido está o update de um cliente (issue #149).
 *
 * Derivado da cadência (dia da semana × hora-alvo) contra o último envio —
 * nunca marcado à mão. Três degraus, e a ordem da fila é exatamente esta:
 * quem já passou da hora vem antes de quem vence hoje, que vem antes de
 * quem está em dia.
 */
enum UpdateUrgency: string
{
    case Overdue = 'overdue';
    case DueToday = 'due_today';
    case OnTrack = 'on_track';

    public function label(): string
    {
        return match ($this) {
            self::Overdue => 'Atrasado',
            self::DueToday => 'Vence hoje',
            self::OnTrack => 'Em dia',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Overdue => 'red',
            self::DueToday => 'amber',
            self::OnTrack => 'emerald',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Overdue => 'exclamation-circle',
            self::DueToday => 'bell-alert',
            self::OnTrack => 'check-circle',
        };
    }

    /**
     * Se este update conta como devido — o que a badge da sidebar soma.
     * "Em dia" é o único que não conta.
     */
    public function isDue(): bool
    {
        return $this !== self::OnTrack;
    }

    /**
     * A posição do degrau na fila. Menor vem primeiro.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Overdue => 0,
            self::DueToday => 1,
            self::OnTrack => 2,
        };
    }
}
