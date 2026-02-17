<?php

namespace App\Enums;

enum RecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    /**
     * Get the human-readable label in PT-BR.
     */
    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Diário',
            self::Weekdays => 'Dias úteis',
            self::Weekly => 'Semanal',
            self::Biweekly => 'Quinzenal',
            self::Monthly => 'Mensal',
        };
    }

    /**
     * Get the color associated with this frequency.
     */
    public function color(): string
    {
        return match ($this) {
            self::Daily => 'red',
            self::Weekdays => 'orange',
            self::Weekly => 'blue',
            self::Biweekly => 'purple',
            self::Monthly => 'zinc',
        };
    }

    /**
     * Get the icon associated with this frequency.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Daily => 'sun',
            self::Weekdays => 'briefcase',
            self::Weekly => 'calendar',
            self::Biweekly => 'calendar-days',
            self::Monthly => 'calendar-date-range',
        };
    }

    /**
     * Get the description for this frequency.
     */
    public function description(): string
    {
        return match ($this) {
            self::Daily => 'Todos os dias',
            self::Weekdays => 'Segunda a sexta',
            self::Weekly => 'Uma vez por semana',
            self::Biweekly => 'A cada duas semanas',
            self::Monthly => 'Uma vez por mês',
        };
    }
}
