<?php

namespace App\Observers;

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Exceptions\WaitingRequiresWaitingForException;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\DailyPlan;
use Carbon\Carbon;

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
        $activity->waiting_for = $this->normalizeWaitingFor($activity->waiting_for);

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
     * Trim `waiting_for` and treat a blank result as absent, so a
     * whitespace-only value never counts as "esperando quem" being set.
     */
    private function normalizeWaitingFor(?string $value): ?string
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
     * Auto-adds activity to today's daily plan if due_date is today.
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

        $this->addToDailyPlanIfDueToday($activity);
    }

    /**
     * Handle the Activity "updating" event.
     *
     * Records a status change when the status field is modified.
     * Auto-adds activity to today's daily plan if due_date changes to today.
     * Syncs pivot_completed_at when status changes to/from done.
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

            $this->syncDailyPlanCompletedAt($activity);
        }

        if ($activity->isDirty('due_date')) {
            $this->addToDailyPlanIfDueToday($activity);
        }
    }

    /**
     * Sync the pivot completed_at when status changes to/from done.
     */
    private function syncDailyPlanCompletedAt(Activity $activity): void
    {
        $plan = DailyPlan::query()
            ->whereDate('date', Carbon::today())
            ->first();

        if (! $plan) {
            return;
        }

        if (! $plan->tasks()->where('activities.id', $activity->id)->exists()) {
            return;
        }

        $newStatus = $activity->status;
        $oldStatus = $activity->getOriginal('status');

        if ($newStatus === ActivityStatus::Done && $oldStatus !== ActivityStatus::Done) {
            $plan->tasks()->updateExistingPivot($activity->id, ['completed_at' => now()]);
        } elseif ($newStatus !== ActivityStatus::Done && $oldStatus === ActivityStatus::Done) {
            $plan->tasks()->updateExistingPivot($activity->id, ['completed_at' => null]);
        }
    }

    /**
     * Add the activity to today's daily plan if due_date is today.
     */
    private function addToDailyPlanIfDueToday(Activity $activity): void
    {
        if ($activity->due_date === null || ! $activity->due_date->isToday()) {
            return;
        }

        $plan = DailyPlan::getOrCreateForDate(Carbon::today());

        if ($plan->tasks()->where('activities.id', $activity->id)->exists()) {
            return;
        }

        $maxOrder = $plan->tasks()->max('daily_plan_activity.sort_order') ?? -1;
        $plan->tasks()->attach($activity->id, ['sort_order' => $maxOrder + 1]);
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
