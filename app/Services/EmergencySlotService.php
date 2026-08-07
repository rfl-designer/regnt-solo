<?php

namespace App\Services;

use App\Enums\ServiceClass;
use App\Exceptions\SingleActiveEmergencyException;
use App\Models\Activity;
use Illuminate\Support\Facades\DB;

/**
 * The board's single Emergência slot, and the only supported way to hand
 * it from one activity to another (issue #143).
 *
 * Every surface that offers "Substituir" — the Emergência modal, the Task
 * Modal's inline conflict, the Kanban's blocked move — goes through
 * {@see swap()} rather than writing the two saves itself. That is what
 * makes the guarantee the criterion asks for actually true: the demotion
 * and whatever promotes the new Emergência share one transaction, so the
 * board is never left with zero Emergências because the second write
 * failed.
 */
class EmergencySlotService
{
    /**
     * The activity currently holding the slot, if any.
     *
     * @param  list<int|null>  $excluding  Activities that must not count as the occupant.
     */
    public function active(array $excluding = []): ?Activity
    {
        $excluding = array_values(array_filter($excluding, fn (?int $id): bool => $id !== null));

        return Activity::query()
            ->activeEmergency()
            ->when($excluding !== [], fn ($query) => $query->whereNotIn('id', $excluding))
            ->orderBy('emergency_since')
            ->orderBy('id')
            ->first();
    }

    /**
     * Hand the slot over: demote the Emergência that holds it, then run
     * `$promote` for the activity taking its place — both in one
     * transaction, chained so the promotion is only attempted once the
     * demotion has actually been saved, and rolled back together if the
     * promotion is refused.
     *
     * The demotion candidate is re-read *inside* the transaction and
     * checked against the slot as it stands now, because a modal can sit
     * open for a long time:
     *
     * - if it is no longer an active Emergência (concluded or reclassified
     *   meanwhile) nothing is demoted — overwriting it would erase a newer
     *   decision, and the slot it used to hold is free anyway;
     * - if somebody else now holds the slot, the swap is refused with
     *   {@see SingleActiveEmergencyException} carrying the *current*
     *   occupant, so the caller can ask the question again against real
     *   state instead of acting on a stale answer.
     *
     * @param  callable(): void  $promote  What actually classifies/moves the new Emergência.
     *
     * @throws SingleActiveEmergencyException
     */
    public function swap(?int $demoteId, int $targetId, callable $promote): void
    {
        DB::transaction(function () use ($demoteId, $targetId, $promote): void {
            if ($demoteId !== null) {
                $demote = Activity::query()->whereKey($demoteId)->first();

                if ($demote !== null && $demote->isActiveEmergency()) {
                    $demote->update(['service_class' => ServiceClass::Standard]);
                }
            }

            $stillOccupied = $this->active([$demoteId, $targetId]);

            if ($stillOccupied !== null) {
                throw new SingleActiveEmergencyException($stillOccupied);
            }

            $promote();
        });
    }
}
