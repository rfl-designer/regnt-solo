<?php

namespace App\Enums;

enum ServiceClass: string
{
    case Emergency = 'emergency';
    case FixedDate = 'fixed_date';
    case Standard = 'standard';
    case Intangible = 'intangible';

    /**
     * Get the human-readable label in PT-BR.
     */
    public function label(): string
    {
        return match ($this) {
            self::Emergency => 'Emergência',
            self::FixedDate => 'Data fixa',
            self::Standard => 'Padrão',
            self::Intangible => 'Intangível',
        };
    }

    /**
     * Get the color associated with this service class.
     */
    public function color(): string
    {
        return match ($this) {
            self::Emergency => 'red',
            self::FixedDate => 'amber',
            self::Standard => 'blue',
            self::Intangible => 'zinc',
        };
    }

    /**
     * Get the icon associated with this service class.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Emergency => 'fire',
            self::FixedDate => 'calendar-days',
            self::Standard => 'minus',
            self::Intangible => 'sparkles',
        };
    }
}
