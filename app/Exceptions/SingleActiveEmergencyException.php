<?php

namespace App\Exceptions;

use App\Models\Activity;
use App\Observers\ActivityObserver;
use DomainException;

/**
 * Thrown when a second Emergência would become active on the board.
 *
 * "Ativa" means: classified as Emergência and not yet in Feito (done
 * Emergências are history, not board state). Enforced at the Eloquent seam
 * ({@see ActivityObserver::saving()}), so the refusal is identical from
 * the Kanban, the Task Modal, the Inbox, the Command Palette, MCP tools
 * and tinker.
 *
 * The exception deliberately carries the *conflicting* activity, not just
 * a message: every caller needs it to offer the only two honest ways
 * forward — keep the current Emergência, or demote it and promote the new
 * one. The UI renders that as a "Manter a atual / Substituir" modal; MCP
 * clients get the same data as a structured error plus the instruction to
 * perform the swap in two explicit calls. There is intentionally no
 * `force` parameter anywhere: a swap must always be a decision about which
 * of the two is the emergency, never a flag.
 */
class SingleActiveEmergencyException extends DomainException implements DomainRefusal
{
    public function __construct(public readonly Activity $activeEmergency)
    {
        parent::__construct(self::messageFor($activeEmergency));
    }

    /**
     * The canonical PT-BR message for this refusal, naming the Emergência
     * that is already holding the slot.
     */
    public static function messageFor(Activity $activeEmergency): string
    {
        return sprintf(
            'Já existe uma Emergência ativa: "%s" (#%d). Só pode haver uma Emergência no board — conclua ou rebaixe a atual antes de classificar outra.',
            $activeEmergency->title,
            $activeEmergency->id,
        );
    }

    /**
     * The active Emergência as a flat payload, for MCP error bodies and
     * for surfaces that render the conflict without re-querying.
     *
     * @return array{id: int, title: string, reason: string|null, age_in_days: int}
     */
    public function activeEmergencyContext(): array
    {
        return [
            'id' => $this->activeEmergency->id,
            'title' => $this->activeEmergency->title,
            'reason' => $this->activeEmergency->emergency_reason,
            'age_in_days' => $this->activeEmergency->emergencyDays(),
        ];
    }
}
