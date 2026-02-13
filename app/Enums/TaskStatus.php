<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Inbox = 'inbox';
    case Backlog = 'backlog';
    case Todo = 'todo';
    case Doing = 'doing';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'Caixa de Entrada',
            self::Backlog => 'Backlog',
            self::Todo => 'A Fazer',
            self::Doing => 'Fazendo',
            self::Done => 'Concluído',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Inbox => 'zinc',
            self::Backlog => 'slate',
            self::Todo => 'sky',
            self::Doing => 'amber',
            self::Done => 'green',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Inbox => 'inbox',
            self::Backlog => 'queue-list',
            self::Todo => 'clipboard-document-list',
            self::Doing => 'play-circle',
            self::Done => 'check-circle',
        };
    }
}
