<?php

namespace App\Exceptions;

use App\Observers\ActivityObserver;
use DomainException;

/**
 * Thrown when an activity is moved into a waiting status (Aguardando
 * aprovação, Esperando, Aguardando validação) without "esperando quem"
 * (waiting_for) being set. This is a domain invariant enforced at the
 * Eloquent seam (Activity saving, see {@see ActivityObserver}),
 * so every origin — Kanban, Task Modal, MCP tools, tinker — gets the same
 * refusal. Client-side waits (Aguardando aprovação/validação) are
 * auto-filled from the activity's effective client before this guard runs,
 * so it only actually fires when there is no effective client to fall back
 * on, or for the internal wait (Esperando), which always requires an
 * explicit name.
 */
class WaitingRequiresWaitingForException extends DomainException implements DomainRefusal
{
    /**
     * The single canonical PT-BR message for this refusal. UI toasts and
     * MCP error responses must reuse this constant verbatim rather than
     * writing their own wording, so the "não" is identical everywhere.
     */
    public const string MESSAGE = 'Mover para um status de espera exige informar "esperando quem".';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
