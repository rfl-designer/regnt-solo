<?php

namespace App\Enums;

enum DocumentType: string
{
    case Prd = 'prd';
    case Spec = 'spec';
    case Decision = 'decision';
    case Note = 'note';
    case Reference = 'reference';

    /**
     * Get the human-readable label in PT-BR.
     */
    public function label(): string
    {
        return match ($this) {
            self::Prd => 'PRD',
            self::Spec => 'Especificação',
            self::Decision => 'Decisão',
            self::Note => 'Nota',
            self::Reference => 'Referência',
        };
    }

    /**
     * Get the icon associated with this document type.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Prd => 'document-text',
            self::Spec => 'code-bracket',
            self::Decision => 'light-bulb',
            self::Note => 'pencil-square',
            self::Reference => 'book-open',
        };
    }

    /**
     * Get the color associated with this document type.
     */
    public function color(): string
    {
        return match ($this) {
            self::Prd => 'indigo',
            self::Spec => 'blue',
            self::Decision => 'amber',
            self::Note => 'zinc',
            self::Reference => 'emerald',
        };
    }
}
