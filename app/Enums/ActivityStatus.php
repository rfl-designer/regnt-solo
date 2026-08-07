<?php

namespace App\Enums;

enum ActivityStatus: string
{
    case Inbox = 'inbox';
    case Backlog = 'backlog';
    case AwaitingApproval = 'awaiting_approval';
    case Todo = 'todo';
    case Doing = 'doing';
    case Waiting = 'waiting';
    case AwaitingValidation = 'awaiting_validation';
    case Done = 'done';

    /**
     * Get the human-readable label in PT-BR.
     *
     * "A Fazer" -> "Pronto" and "Concluída" -> "Feito" are label-only
     * renames (issue #142): the persisted values `todo`/`done` never
     * change, only the presentation.
     */
    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'Caixa de Entrada',
            self::Backlog => 'Backlog',
            self::AwaitingApproval => 'Aguardando aprovação',
            self::Todo => 'Pronto',
            self::Doing => 'Fazendo',
            self::Waiting => 'Esperando',
            self::AwaitingValidation => 'Aguardando validação',
            self::Done => 'Feito',
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
            self::AwaitingApproval => 'violet',
            self::Todo => 'blue',
            self::Doing => 'amber',
            self::Waiting => 'orange',
            self::AwaitingValidation => 'teal',
            self::Done => 'emerald',
        };
    }

    /**
     * Get the hex color for inline styles (e.g., segmented bars).
     */
    public function hexColor(): string
    {
        return match ($this) {
            self::Inbox => '#a1a1aa',            // zinc-400
            self::Backlog => '#94a3b8',          // slate-400
            self::AwaitingApproval => '#a78bfa', // violet-400
            self::Todo => '#60a5fa',             // blue-400
            self::Doing => '#fbbf24',            // amber-400
            self::Waiting => '#fb923c',          // orange-400
            self::AwaitingValidation => '#2dd4bf', // teal-400
            self::Done => '#34d399',             // emerald-400
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
            self::AwaitingApproval => 'paper-airplane',
            self::Todo => 'clipboard-document-list',
            self::Doing => 'play-circle',
            self::Waiting => 'pause-circle',
            self::AwaitingValidation => 'eye',
            self::Done => 'check-circle',
        };
    }

    /**
     * The 7-column board order (issue #142): Inbox stays off-board, triaged
     * separately. This is the single source of truth for column order —
     * surfaces that render the board must reuse it rather than hardcoding
     * their own list of statuses.
     *
     * @return list<self>
     */
    public static function boardOrder(): array
    {
        return [
            self::Backlog,
            self::AwaitingApproval,
            self::Todo,
            self::Doing,
            self::Waiting,
            self::AwaitingValidation,
            self::Done,
        ];
    }

    /**
     * Where this status sits along the flow, as a comparable rank: its
     * position in {@see boardOrder()}, with Inbox (off-board triage) ranked
     * before everything.
     *
     * This is what makes "moved forward" and "sent back" answerable without
     * enumerating pairs of statuses — the Spec lifecycle (issue #146) needs
     * exactly that distinction to tell an approval (Aguardando aprovação ->
     * Pronto) from a reprovação (Aguardando aprovação -> Backlog).
     */
    public function flowRank(): int
    {
        $index = array_search($this, self::boardOrder(), true);

        return $index === false ? -1 : $index;
    }

    /**
     * Whether moving from this status to $other is a move forward along the
     * flow rather than a step back.
     */
    public function isAheadOf(self $other): bool
    {
        return $this->flowRank() > $other->flowRank();
    }

    /**
     * Whether this status represents the activity waiting on someone before
     * it can move forward — client-side (Aguardando aprovação/validação) or
     * internal (Esperando). Waiting statuses require "esperando quem" to be
     * set (enforced by ActivityObserver) and are excluded from daily
     * planning suggestions/carry-over.
     */
    public function isWaiting(): bool
    {
        return match ($this) {
            self::AwaitingApproval, self::Waiting, self::AwaitingValidation => true,
            default => false,
        };
    }

    /**
     * Whether this is a client-side wait: "esperando quem" is auto-filled
     * from the activity's effective client rather than asked interactively.
     */
    public function isClientWaiting(): bool
    {
        return match ($this) {
            self::AwaitingApproval, self::AwaitingValidation => true,
            default => false,
        };
    }

    /**
     * Whether this is an internal wait: "esperando quem" has no sensible
     * default and must be collected interactively (blocking mini modal).
     */
    public function isInternalWaiting(): bool
    {
        return $this === self::Waiting;
    }

    /**
     * Whether the activity is actively workable right now — ready to start
     * or already in progress. Excludes triage (Inbox), planning (Backlog),
     * any wait, and completion (Done). Surfaces that need "what can I work
     * on" (suggestions, focus views) should pick by this meaning rather
     * than enumerating statuses themselves.
     */
    public function isWorkable(): bool
    {
        return match ($this) {
            self::Todo, self::Doing => true,
            default => false,
        };
    }
}
