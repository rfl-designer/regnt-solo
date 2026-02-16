<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Backlog = 'backlog';
    case Active = 'active';
    case Paused = 'paused';
    case Done = 'done';
    case Maintenance = 'maintenance';
    case Archived = 'archived';

    /**
     * Get the human-readable label in PT-BR.
     */
    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Backlog',
            self::Active => 'Ativo',
            self::Paused => 'Pausado',
            self::Done => 'Concluído',
            self::Maintenance => 'Manutenção',
            self::Archived => 'Arquivado',
        };
    }

    /**
     * Get the color associated with this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Backlog => 'zinc',
            self::Active => 'emerald',
            self::Paused => 'amber',
            self::Done => 'sky',
            self::Maintenance => 'violet',
            self::Archived => 'zinc',
        };
    }

    /**
     * Get the icon associated with this status.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Backlog => 'inbox-stack',
            self::Active => 'play-circle',
            self::Paused => 'pause-circle',
            self::Done => 'check-circle',
            self::Maintenance => 'wrench-screwdriver',
            self::Archived => 'archive-box',
        };
    }
}
