<?php

namespace App\Enums;

enum FeatureStatus: string
{
    case Draft = 'draft';
    case Backlog = 'backlog';
    case Todo = 'todo';
    case Doing = 'doing';
    case Done = 'done';

    /**
     * Get the human-readable label in PT-BR.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Backlog => 'Backlog',
            self::Todo => 'A Fazer',
            self::Doing => 'Em Progresso',
            self::Done => 'Concluída',
        };
    }

    /**
     * Get the color associated with this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Backlog => 'slate',
            self::Todo => 'blue',
            self::Doing => 'amber',
            self::Done => 'emerald',
        };
    }

    /**
     * Get the hex color for inline styles.
     */
    public function hexColor(): string
    {
        return match ($this) {
            self::Draft => '#71717a',   // zinc-500
            self::Backlog => '#94a3b8', // slate-400
            self::Todo => '#60a5fa',    // blue-400
            self::Doing => '#fbbf24',   // amber-400
            self::Done => '#34d399',    // emerald-400
        };
    }

    /**
     * Get the icon associated with this status.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'document',
            self::Backlog => 'archive-box',
            self::Todo => 'clipboard-document-list',
            self::Doing => 'play-circle',
            self::Done => 'check-circle',
        };
    }
}
