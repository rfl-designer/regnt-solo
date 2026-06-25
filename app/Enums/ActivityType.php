<?php

namespace App\Enums;

enum ActivityType: string
{
    case Epic = 'epic';
    case Issue = 'issue';
    case Task = 'task';
    case Draft = 'draft';

    /**
     * Get the human-readable label in PT-BR.
     *
     * For Issue, the client-facing label (Fatia/Follow-up/Avulsa) is derived
     * from the parent on the Activity model, not from the type alone.
     */
    public function label(): string
    {
        return match ($this) {
            self::Epic => 'Épico',
            self::Issue => 'Issue',
            self::Task => 'Task',
            self::Draft => 'Ideia',
        };
    }

    /**
     * Get the color associated with this type.
     */
    public function color(): string
    {
        return match ($this) {
            self::Epic => 'violet',
            self::Issue => 'blue',
            self::Task => 'zinc',
            self::Draft => 'amber',
        };
    }

    /**
     * Get the icon associated with this type.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Epic => 'rectangle-stack',
            self::Issue => 'document-text',
            self::Task => 'check-circle',
            self::Draft => 'light-bulb',
        };
    }
}
