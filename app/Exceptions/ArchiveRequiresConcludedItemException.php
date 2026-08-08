<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when something tries to archive an activity that isn't a concluded
 * board item (issue #147).
 *
 * Archiving means "já revisei isto no ritual" — it is the first step of the
 * morning ritual clearing the Feito column. Applied to anything else it
 * would hide live work: an item in Pronto or Fazendo would vanish from the
 * only surface that shows it, with no status change to explain where it
 * went.
 */
class ArchiveRequiresConcludedItemException extends DomainException implements DomainRefusal
{
    /**
     * The single canonical PT-BR message for this refusal, reused verbatim
     * by every surface so the "não" reads the same everywhere.
     */
    public const string MESSAGE = 'Só itens concluídos do quadro podem ser arquivados.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
