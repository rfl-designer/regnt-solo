<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when an activity is classified as "Data fixa" (fixed_date service
 * class) without a due date. This is a domain invariant enforced at the
 * Eloquent seam (Activity saving), so every origin — Kanban, Task Modal,
 * MCP tools, tinker — gets the same refusal.
 */
class FixedDateRequiresDueDateException extends DomainException
{
    /**
     * The single canonical PT-BR message for this refusal. UI toasts and
     * MCP error responses must reuse this constant verbatim rather than
     * writing their own wording, so the "não" is identical everywhere.
     */
    public const string MESSAGE = 'Classificar como Data fixa exige uma data de vencimento.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
