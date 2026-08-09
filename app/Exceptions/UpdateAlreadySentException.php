<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A recusa a marcar como enviado o que já foi enviado (issue #149).
 *
 * A data de envio é o que fecha a janela do próximo update e zera o relógio
 * da cadência. Carimbá-la de novo reescreveria o histórico ("foi isto que
 * eu te disse, nesta data") e faria a janela seguinte perder o intervalo
 * entre os dois carimbos.
 */
class UpdateAlreadySentException extends RuntimeException implements DomainRefusal
{
    public function __construct()
    {
        parent::__construct('Este update já foi marcado como enviado.');
    }
}
