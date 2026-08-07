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
    public function __construct()
    {
        parent::__construct('Classificar como Data fixa exige uma data de vencimento (due date).');
    }
}
