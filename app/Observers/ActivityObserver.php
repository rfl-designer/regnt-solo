<?php

namespace App\Observers;

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\DoingWipLimitExceededException;
use App\Exceptions\EmergencyRequiresReasonException;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Exceptions\SingleActiveEmergencyException;
use App\Exceptions\WaitingRequiresWaitingForException;
use App\Models\Activity;
use App\Models\ActivityStatusChange;

class ActivityObserver
{
    /**
     * Handle the Activity "saving" event.
     *
     * Enforces the domain invariant that classifying an activity as "Data
     * fixa" requires a due date. This is the application-level choke point
     * for the guard — it fires on every model save regardless of origin
     * (Kanban, Task Modal, MCP tools, tinker), producing the friendly
     * {@see FixedDateRequiresDueDateException} with a PT-BR message.
     *
     * Limitation: this observer only fires for saves that go through an
     * Eloquent model instance. Bulk writes that skip model events —
     * `Activity::query()->update([...])`, `Activity::query()->upsert(...)`,
     * and raw `DB::table('activities')->update([...])` — never reach this
     * method. Those are backstopped by the `chk_activities_fixed_date_
     * requires_due_date` CHECK constraint added in
     * `2026_08_07_092633_add_fixed_date_due_date_check_to_activities_table`,
     * which is the actual source of truth for the invariant at the
     * database level; this method exists to turn that into a readable
     * PT-BR error before the query ever reaches the database.
     */
    public function saving(Activity $activity): void
    {
        if ($activity->service_class === ServiceClass::FixedDate && $activity->due_date === null) {
            throw new FixedDateRequiresDueDateException;
        }

        $this->handleWaitingState($activity);
        $this->handleEmergencyState($activity);
        $this->enforceDoingWipLimit($activity);
    }

    /**
     * Concurrency note for the two cross-row guards below (WIP limit and
     * single Emergência): both read the current board state and then let
     * the save write, so two *simultaneous* writers could each see room and
     * both get in. That is accepted, not overlooked.
     *
     * SoloBoard is single-user by design (no `user_id` anywhere, one seeded
     * account), so there is no second human racing the first; the only way
     * to produce the interleaving is to drive two requests at the same
     * instant on purpose. The alternatives all cost more than the risk:
     * neither invariant is expressible as a portable database constraint
     * ("one active Emergência" is cross-row, and MySQL has no partial
     * unique index), and serializing every board write through a singleton
     * lock row would add a bottleneck and a new failure mode to a
     * single-user app to defend against a race that has no realistic
     * trigger.
     *
     * What does hold: the swap flows that *deliberately* touch two rows
     * (demote one Emergência, promote another) run both writes inside one
     * transaction and re-validate the slot under that transaction, so the
     * one multi-row operation the feature actually performs is consistent.
     */

    /**
     * Enforce the two Emergência invariants (issue #143):
     *
     * - **Motivo obrigatório.** Being an Emergência is what lets an item
     *   jump the WIP limit, so it must always be justified: any save that
     *   leaves the activity classified as Emergência with a blank
     *   `emergency_reason` is refused. Blank/whitespace-only reasons are
     *   normalized to null first, so `'   '` never counts as a motivo.
     * - **Uma só Emergência ativa.** At most one activity may be an
     *   Emergência outside Feito at any time. The refusal carries the
     *   conflicting activity ({@see SingleActiveEmergencyException}) so
     *   every caller can offer the same "manter a atual / substituir"
     *   choice instead of a silent failure.
     *
     * Reclassifying to another service class clears both the motivo and
     * `emergency_since` — the justification belongs to the classification,
     * not to the activity. Concluding it does *not* clear them: a done
     * Emergência keeps its motivo as history, and stops occupying the slot
     * only because it left the board.
     *
     * `emergency_since` is stamped fresh on every genuine *entry* into the
     * class (a new record created as Emergência, or a reclassification into
     * it), which is also what stops it being forged: it isn't fillable
     * ({@see Activity::$fillable}) and any entry overwrites it with now().
     *
     * The uniqueness check only runs on saves that could actually create a
     * second active Emergência — entering the class, or a status change
     * that brings an existing Emergência back onto the board (e.g. Feito ->
     * Fazendo). Editing an Emergência that already holds the slot never
     * trips over itself.
     */
    private function handleEmergencyState(Activity $activity): void
    {
        $activity->emergency_reason = $this->normalizeBlankToNull($activity->emergency_reason);

        if ($activity->service_class !== ServiceClass::Emergency) {
            $activity->emergency_reason = null;
            $activity->emergency_since = null;

            return;
        }

        if (blank($activity->emergency_reason)) {
            throw new EmergencyRequiresReasonException;
        }

        $enteringEmergencyThisSave = ! $activity->exists || $activity->isDirty('service_class');

        if ($activity->isActiveEmergency() && ($enteringEmergencyThisSave || $activity->isDirty('status'))) {
            $conflict = Activity::query()
                ->activeEmergency()
                ->when($activity->exists, fn ($query) => $query->whereKeyNot($activity->getKey()))
                ->orderBy('emergency_since')
                ->orderBy('id')
                ->first();

            if ($conflict !== null) {
                throw new SingleActiveEmergencyException($conflict);
            }
        }

        if ($enteringEmergencyThisSave || $activity->emergency_since === null) {
            $activity->emergency_since = now();
        }
    }

    /**
     * Enforce the WIP limit on "Fazendo" (issue #143).
     *
     * The guard runs whenever *this save* is what makes the activity a
     * counting item in Fazendo. That is two distinct transitions, not one:
     *
     * - entering the column (a new record created there, or a status change
     *   into it); and
     * - **losing the Emergência exemption while already there** — demoting
     *   the Emergência that was furando o limite would otherwise quietly
     *   leave three ordinary items in Fazendo, and that is exactly what the
     *   "Substituir" flow does when the current Emergência sits in the
     *   column. Refusing it is the honest answer: take something out of
     *   Fazendo first, then swap.
     *
     * Saves that change neither of those are never gated, so an over-limit
     * column (which the Emergência exception legitimately produces, hence
     * the board's "3/2") never becomes unusable.
     *
     * Two deliberate exclusions:
     *
     * - **Emergência passes.** This is the whole point of classifying
     *   something as an Emergência, and the reason the classification is
     *   guarded so tightly above.
     * - **Only board items count.** The limit is about the board, so it
     *   counts and gates exactly what the board renders as a card
     *   ({@see Activity::isLeaf()} / {@see Activity::scopeLeaf()}) —
     *   issues and atomic epics. Personal tasks and parent epics never
     *   appear as Fazendo cards and are neither counted nor refused.
     *
     * Known gap: an epic in Fazendo that becomes a leaf because its last
     * child was deleted starts counting without any save of its own passing
     * through here. Refusing a child's deletion because of its parent's
     * column would be a worse answer than briefly reading "3/2", so the
     * count simply catches up.
     */
    private function enforceDoingWipLimit(Activity $activity): void
    {
        if ($activity->status !== ActivityStatus::Doing) {
            return;
        }

        if ($activity->service_class === ServiceClass::Emergency) {
            return;
        }

        $enteringDoingThisSave = ! $activity->exists || $activity->isDirty('status');
        $losingEmergencyExemption = $activity->exists
            && $activity->isDirty('service_class')
            && $activity->getOriginal('service_class') === ServiceClass::Emergency;

        if (! $enteringDoingThisSave && ! $losingEmergencyExemption) {
            return;
        }

        if (! $activity->isLeaf()) {
            return;
        }

        $limit = (int) config('soloboard.wip_limit_doing', 2);

        if ($limit <= 0) {
            return;
        }

        $inDoing = Activity::query()
            ->leaf()
            ->where('status', ActivityStatus::Doing)
            ->when($activity->exists, fn ($query) => $query->whereKeyNot($activity->getKey()))
            ->count();

        if ($inDoing >= $limit) {
            throw new DoingWipLimitExceededException($limit);
        }
    }

    /**
     * Enforce the "esperando quem" (waiting_for) / "desde quando"
     * (waiting_since) invariant for the three waiting statuses (issue
     * #142): Aguardando aprovação, Esperando, Aguardando validação.
     *
     * - `waiting_for` is normalized (trimmed, blank -> null) before any of
     *   the rules below run, so `''`/`'   '` are treated exactly like null
     *   rather than slipping through as an "anonymous" wait.
     * - A status is null or non-waiting: both fields are cleared. `null`
     *   counts as non-waiting here (a nullable status is still a valid
     *   Eloquent state even though no current UI produces it directly).
     * - Every genuine *entry* into a waiting status this save — whether
     *   this is a brand new record, a move from a non-waiting status, or a
     *   move between two waiting statuses (e.g. `awaiting_approval` ->
     *   `waiting`) — requires a fresh, explicit `waiting_for` for this
     *   save: client-side waits (Aguardando aprovação/validação) resolve
     *   it from the effective client when not explicitly provided;
     *   the internal wait (Esperando) has no such fallback, so an
     *   inherited value from the previous wait is discarded, which is
     *   exactly what forces the interactive prompt in the UI.
     * - Staying in the *same* client-side wait while the project/client
     *   changes (and `waiting_for` itself wasn't explicitly touched this
     *   save) re-resolves "esperando quem" from the new effective client,
     *   instead of keeping the previous client's name.
     * - Any waiting status left with no `waiting_for` after the rules
     *   above is refused.
     * - `waiting_since` is stamped fresh on every genuine entry (as
     *   defined above) and left untouched on saves that don't change
     *   status while already sitting in the same wait — this is also what
     *   stops a caller from forging the timestamp via mass assignment
     *   (`waiting_since` isn't fillable; see {@see Activity::$fillable}),
     *   since any entry transition always overwrites it with `now()` here.
     *
     * Fires on every Eloquent save regardless of origin (Kanban, Task
     * Modal, MCP tools, tinker), same as the fixed_date guard above.
     */
    private function handleWaitingState(Activity $activity): void
    {
        $activity->waiting_for = $this->normalizeBlankToNull($activity->waiting_for);

        $newStatus = $activity->status;

        if ($newStatus === null || ! $newStatus->isWaiting()) {
            if ($activity->waiting_for !== null) {
                $activity->waiting_for = null;
            }

            if ($activity->waiting_since !== null) {
                $activity->waiting_since = null;
            }

            return;
        }

        // New records have no "original" status to diff against — every
        // create into a waiting status is an entry. `isDirty` on a fresh
        // model always reports false (its original is synced to itself at
        // construction time), so `exists` is checked first.
        $enteringWaitThisSave = ! $activity->exists || $activity->isDirty('status');

        $waitingForExplicit = $activity->exists
            ? $activity->isDirty('waiting_for')
            : $activity->waiting_for !== null;

        if ($enteringWaitThisSave) {
            if ($newStatus->isInternalWaiting() && ! $waitingForExplicit) {
                // No client to fall back on, and no fresh answer given —
                // even a value inherited from a previous wait is discarded
                // so the guard below refuses and the UI's blocking prompt
                // takes over.
                $activity->waiting_for = null;
            } elseif ($newStatus->isClientWaiting() && ! $waitingForExplicit) {
                $activity->waiting_for = $activity->effective_client?->name;
            }
        } elseif ($newStatus->isClientWaiting()
            && ! $waitingForExplicit
            && ($activity->isDirty('project_id') || $activity->isDirty('client_id'))
        ) {
            $activity->waiting_for = $activity->effective_client?->name;
        }

        if (blank($activity->waiting_for)) {
            throw new WaitingRequiresWaitingForException;
        }

        if ($enteringWaitThisSave || $activity->waiting_since === null) {
            $activity->waiting_since = now();
        }
    }

    /**
     * Trim a free-text guard input ("esperando quem", "motivo da
     * Emergência") and treat a blank result as absent, so a
     * whitespace-only value never counts as the field being set.
     */
    private function normalizeBlankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Handle the Activity "creating" event.
     *
     * Sets completed_at when an activity is created directly with status done.
     */
    public function creating(Activity $activity): void
    {
        if ($activity->status === ActivityStatus::Done && $activity->completed_at === null) {
            $activity->completed_at = now();
        }

        $this->clearDirectClientWhenProjectPresent($activity);
    }

    /**
     * Handle the Activity "created" event.
     *
     * Records the initial status when an activity is created.
     */
    public function created(Activity $activity): void
    {
        if ($activity->status !== null) {
            ActivityStatusChange::create([
                'activity_id' => $activity->id,
                'from_status' => null,
                'to_status' => $activity->status,
                'changed_at' => now(),
            ]);
        }
    }

    /**
     * Handle the Activity "updating" event.
     *
     * Records a status change when the status field is modified.
     *
     * Nothing here touches a daily plan any more (issue #147): the plan is
     * gone, and with it the two hidden writes it used to trigger — an item
     * due today silently joining a list, and concluding an item silently
     * ticking it off in a second place. The board is the only record.
     */
    public function updating(Activity $activity): void
    {
        $this->clearDirectClientWhenProjectPresent($activity);

        if ($activity->isDirty('status') && $activity->status !== null) {
            ActivityStatusChange::create([
                'activity_id' => $activity->id,
                'from_status' => $activity->getOriginal('status'),
                'to_status' => $activity->status,
                'changed_at' => now(),
            ]);
        }

        $this->clearArchiveOnReopen($activity);
    }

    /**
     * Leaving Feito un-archives the activity (issue #147).
     *
     * "Já revisei isto no ritual" is a statement about a conclusion, and
     * reopening the item retracts the conclusion it was about. Without
     * this, an item archived and then reopened comes back to Feito already
     * archived — invisible in the column *and* in the ritual's first step,
     * so its new conclusion would never be reviewed by anyone.
     *
     * Only the timestamp is touched: the status history is the record of
     * what happened, and archiving was never part of it.
     */
    private function clearArchiveOnReopen(Activity $activity): void
    {
        if (! $activity->isDirty('status')) {
            return;
        }

        if ($activity->getOriginal('status') !== ActivityStatus::Done) {
            return;
        }

        if ($activity->status !== ActivityStatus::Done && $activity->archived_at !== null) {
            $activity->archived_at = null;
        }
    }

    /**
     * Enforce the invariant that an activity's project link and direct client
     * link never coexist: whenever a project is set, clear the direct client.
     */
    private function clearDirectClientWhenProjectPresent(Activity $activity): void
    {
        if ($activity->project_id !== null && $activity->client_id !== null) {
            $activity->client_id = null;
        }
    }
}
