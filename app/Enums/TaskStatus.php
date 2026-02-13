<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Inbox = 'inbox';
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
            self::Inbox => 'Caixa de Entrada',
            self::Backlog => 'Backlog',
            self::Todo => 'A Fazer',
            self::Doing => 'Fazendo',
            self::Done => 'Concluída',
        };
    }

    /**
     * Get the color associated with this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Inbox => 'zinc',
            self::Backlog => 'slate',
            self::Todo => 'blue',
            self::Doing => 'amber',
            self::Done => 'emerald',
        };
    }

    /**
     * Get the icon associated with this status.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Inbox => 'inbox',
            self::Backlog => 'archive-box',
            self::Todo => 'clipboard-document-list',
            self::Doing => 'play-circle',
            self::Done => 'check-circle',
        };
    }
}
