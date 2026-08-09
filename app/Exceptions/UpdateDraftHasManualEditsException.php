<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A recusa a remontar um rascunho que foi mexido à mão (issue #149).
 *
 * Regerar é destrutivo: o texto novo vem do quadro e substitui tudo. Quando
 * o rascunho ainda é exatamente o que o gerador escreveu, não há nada a
 * perder e a remontagem é livre; quando há edição manual, a perda é real e
 * precisa ser confirmada — modal na página, `force` explícito no MCP.
 */
class UpdateDraftHasManualEditsException extends RuntimeException implements DomainRefusal
{
    public function __construct()
    {
        parent::__construct('Este rascunho foi editado à mão. Regerar descarta o texto atual.');
    }
}
