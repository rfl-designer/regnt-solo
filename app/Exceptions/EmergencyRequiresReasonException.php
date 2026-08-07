<?php

namespace App\Exceptions;

use App\Observers\ActivityObserver;
use DomainException;

/**
 * Thrown when an activity is classified as Emergência without a motivo.
 *
 * An Emergência is the one classification that is allowed to break the WIP
 * limit, so it is also the one that must be justified: the method's whole
 * point is that emergencies are rare and deliberate. The guard lives at the
 * Eloquent seam ({@see ActivityObserver::saving()}), so every origin —
 * Kanban, Task Modal, Inbox, Command Palette, MCP tools, tinker — gets the
 * same refusal with the same PT-BR wording.
 */
class EmergencyRequiresReasonException extends DomainException implements DomainRefusal
{
    /**
     * The single canonical PT-BR message for this refusal. UI toasts and
     * MCP error responses must reuse this constant verbatim rather than
     * writing their own wording, so the "não" is identical everywhere.
     */
    public const string MESSAGE = 'Classificar como Emergência exige informar o motivo.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
