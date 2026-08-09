<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The refusal to promote an Ideia that is not shaped enough to be a bet
 * (issue #148).
 *
 * It carries the *list* of what is missing rather than only a sentence, so
 * every caller can render the same "não" in its own idiom — a toast on the
 * shaping page, a validation error over MCP — without either one inventing
 * its own wording or its own rules.
 */
class ShapingIncompleteException extends RuntimeException
{
    /**
     * @param  list<string>  $missing  The missing sections, in PT-BR, in the order the page shows them.
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct(self::describe($missing));
    }

    /**
     * The single rendering of the refusal, in PT-BR.
     *
     * @param  list<string>  $missing
     */
    public static function describe(array $missing): string
    {
        $list = count($missing) > 1
            ? implode(', ', array_slice($missing, 0, -1)).' e '.end($missing)
            : ($missing[0] ?? '');

        return "Para promover a Ideia a Épico, falta: {$list}.";
    }
}
