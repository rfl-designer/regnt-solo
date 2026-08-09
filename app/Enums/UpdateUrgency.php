<?php

namespace App\Enums;

/**
 * Quão devido está o update de um cliente (issue #149).
 *
 * Três degraus vêm da cadência (dia da semana × hora-alvo) contra o último
 * envio — nunca marcados à mão. O quarto, {@see self::Event}, não vem do
 * relógio: é o que acontece quando o quadro produz uma notícia que não
 * espera a terça-feira (issue #150).
 *
 * A ordem da fila é exatamente a de {@see rank()}: evento antes de
 * atrasado, atrasado antes de quem vence hoje, e por último quem está em
 * dia. Um evento fura a cadência porque a informação envelhece mais rápido
 * que o compromisso semanal — uma entrega parada esperando validação e uma
 * Emergência aberta são as duas coisas que o cliente precisa saber hoje.
 */
enum UpdateUrgency: string
{
    case Event = 'event';
    case Overdue = 'overdue';
    case DueToday = 'due_today';
    case OnTrack = 'on_track';

    public function label(): string
    {
        return match ($this) {
            self::Event => 'Evento',
            self::Overdue => 'Atrasado',
            self::DueToday => 'Vence hoje',
            self::OnTrack => 'Em dia',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Event => 'violet',
            self::Overdue => 'red',
            self::DueToday => 'amber',
            self::OnTrack => 'emerald',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Event => 'bolt',
            self::Overdue => 'exclamation-circle',
            self::DueToday => 'bell-alert',
            self::OnTrack => 'check-circle',
        };
    }

    /**
     * Se este update conta como devido — o que a badge da sidebar soma.
     * "Em dia" é o único que não conta; um evento conta mesmo num cliente
     * cuja cadência ainda nem venceu, que é o ponto do gatilho.
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
            self::Event => 0,
            self::Overdue => 1,
            self::DueToday => 2,
            self::OnTrack => 3,
        };
    }
}
