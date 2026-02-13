<?php

namespace App\Enums;

enum ProjectPriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Alta',
            self::Medium => 'Média',
            self::Low => 'Baixa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::High => 'red',
            self::Medium => 'amber',
            self::Low => 'sky',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::High => 'arrow-up-circle',
            self::Medium => 'minus-circle',
            self::Low => 'arrow-down-circle',
        };
    }

    /**
     * Return the sort weight for ordering (higher priority = higher weight).
     */
    public function weight(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
