<?php

namespace App\Enums;

enum ActivityPriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * Get the human-readable label in PT-BR.
     */
    public function label(): string
    {
        return match ($this) {
            self::Urgent => 'Urgente',
            self::High => 'Alta',
            self::Medium => 'Média',
            self::Low => 'Baixa',
        };
    }

    /**
     * Get the color associated with this priority.
     */
    public function color(): string
    {
        return match ($this) {
            self::Urgent => 'red',
            self::High => 'orange',
            self::Medium => 'blue',
            self::Low => 'zinc',
        };
    }

    /**
     * Get the icon associated with this priority.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Urgent => 'fire',
            self::High => 'arrow-up',
            self::Medium => 'minus',
            self::Low => 'arrow-down',
        };
    }
}
