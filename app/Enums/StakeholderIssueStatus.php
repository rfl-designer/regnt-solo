<?php

namespace App\Enums;

enum StakeholderIssueStatus: string
{
    case Unread = 'unread';
    case ToFeature = 'to_feature';
    case Feature = 'feature';
    case Archived = 'archived';

    /**
     * Get the human-readable label in PT-BR.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unread => 'Não lida',
            self::ToFeature => 'Para feature',
            self::Feature => 'Feature',
            self::Archived => 'Arquivada',
        };
    }

    /**
     * Get the color associated with this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Unread => 'blue',
            self::ToFeature => 'amber',
            self::Feature => 'emerald',
            self::Archived => 'zinc',
        };
    }

    /**
     * Get the icon associated with this status.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Unread => 'inbox',
            self::ToFeature => 'light-bulb',
            self::Feature => 'rectangle-stack',
            self::Archived => 'archive-box',
        };
    }
}
