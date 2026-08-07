<?php

namespace App\Exceptions;

use App\Observers\ActivityObserver;
use DomainException;

/**
 * Thrown when pulling one more board item into "Fazendo" would break the
 * WIP limit (`config('soloboard.wip_limit_doing')`, default 2).
 *
 * This is the method's hardest rule, so it is a guard and not a hint: it
 * lives at the Eloquent seam ({@see ActivityObserver::saving()}) and
 * refuses the write from every origin — Kanban drag-and-drop, Task Modal,
 * MCP tools, tinker. The single documented exception is an item classified
 * as Emergência, which is allowed through precisely because it is the
 * escape hatch the limit is designed to make expensive.
 */
class DoingWipLimitExceededException extends DomainException implements DomainRefusal
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(self::messageFor($limit));
    }

    /**
     * The canonical PT-BR message for this refusal. UI toasts and MCP error
     * responses must reuse this rather than writing their own wording, so
     * the "não" is identical everywhere.
     */
    public static function messageFor(int $limit): string
    {
        return sprintf(
            'Limite de %d itens em Fazendo atingido. Termine ou devolva algo antes de puxar outro — só uma Emergência fura o limite.',
            $limit,
        );
    }
}
