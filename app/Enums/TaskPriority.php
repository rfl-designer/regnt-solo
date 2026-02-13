<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Urgent => 'Urgente',
            self::High => 'Alta',
            self::Medium => 'Média',
            self::Low => 'Baixa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Urgent => 'rose',
            self::High => 'red',
            self::Medium => 'amber',
            self::Low => 'sky',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Urgent => 'fire',
            self::High => 'arrow-up-circle',
            self::Medium => 'minus-circle',
            self::Low => 'arrow-down-circle',
        };
    }
}
