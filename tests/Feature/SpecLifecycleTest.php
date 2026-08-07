<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use Carbon\Carbon;

/**
 * The Spec's lifecycle, derived from the Épico's status history (issue
 * #146). Every fixture writes the history by hand, because that history is
 * the only thing the accessors read — no column records any of these dates.
 */

/**
 * Give an activity a hand-written status history, wiping whatever the
 * observer recorded so the fixture states one sequence only.
 *
 * @param  list<array{0: ActivityStatus, 1: string}>  $steps  [status, moment] pairs, in order.
 */
function withSpecHistory(Activity $activity, array $steps): Activity
{
    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    $previous = null;

    foreach ($steps as [$status, $at]) {
        ActivityStatusChange::factory()->create([
            'activity_id' => $activity->id,
            'from_status' => $previous,
            'to_status' => $status,
            'changed_at' => Carbon::parse($at),
        ]);

        $previous = $status;
    }

    return $activity->fresh();
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a straight run through the lifecycle reports the four dates', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->create(), [
        [ActivityStatus::Backlog, '2026-07-01 09:00'],
        [ActivityStatus::AwaitingApproval, '2026-07-02 09:00'],
        [ActivityStatus::Todo, '2026-07-05 09:00'],
        [ActivityStatus::Doing, '2026-07-06 09:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-10 09:00'],
        [ActivityStatus::Done, '2026-07-12 09:00'],
    ]);

    expect($epic->spec_enviada->toDateTimeString())->toBe('2026-07-02 09:00:00')
        ->and($epic->spec_aprovada->toDateTimeString())->toBe('2026-07-05 09:00:00')
        ->and($epic->spec_entregue->toDateTimeString())->toBe('2026-07-10 09:00:00')
        ->and($epic->spec_validada->toDateTimeString())->toBe('2026-07-12 09:00:00');
});

test('a Spec never sent has no lifecycle at all', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->create(), [
        [ActivityStatus::Backlog, '2026-07-01 09:00'],
        [ActivityStatus::Doing, '2026-07-02 09:00'],
    ]);

    expect($epic->spec_enviada)->toBeNull()
        ->and($epic->spec_aprovada)->toBeNull()
        ->and($epic->hasSpecLifecycle())->toBeFalse()
        ->and($epic->isSpecPending())->toBeFalse();
});

test('enviada keeps the first send, so a re-send after a reprovação does not move it', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-02 09:00'],
        // Reprovada: back to Backlog, which is a step *back* along the flow.
        [ActivityStatus::Backlog, '2026-07-04 09:00'],
        [ActivityStatus::AwaitingApproval, '2026-07-06 09:00'],
        [ActivityStatus::Todo, '2026-07-08 09:00'],
    ]);

    expect($epic->spec_enviada->toDateTimeString())->toBe('2026-07-02 09:00:00')
        ->and($epic->spec_aprovada->toDateTimeString())->toBe('2026-07-08 09:00:00');
});

test('a reprovação is not an approval — leaving backwards leaves the Spec pending', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->create(['status' => ActivityStatus::Backlog]), [
        [ActivityStatus::AwaitingApproval, '2026-07-02 09:00'],
        [ActivityStatus::Backlog, '2026-07-04 09:00'],
    ]);

    expect($epic->spec_aprovada)->toBeNull()
        ->and($epic->hasSpecLifecycle())->toBeTrue()
        ->and($epic->isSpecPending())->toBeTrue();
});

test('aprovada keeps the last approval, because an earlier one no longer stands after a reprovação', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-02 09:00'],
        [ActivityStatus::Todo, '2026-07-03 09:00'],
        // Sent back for a second round, reproved, re-sent and approved again.
        [ActivityStatus::AwaitingApproval, '2026-07-05 09:00'],
        [ActivityStatus::Backlog, '2026-07-06 09:00'],
        [ActivityStatus::AwaitingApproval, '2026-07-07 09:00'],
        [ActivityStatus::Doing, '2026-07-09 09:00'],
    ]);

    expect($epic->spec_aprovada->toDateTimeString())->toBe('2026-07-09 09:00:00');
});

test('entregue and validada keep the last round trip, not the first attempt', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-01 09:00'],
        [ActivityStatus::Todo, '2026-07-02 09:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-04 09:00'],
        // Rejeitada na validação: volta para Fazendo e é reentregue.
        [ActivityStatus::Doing, '2026-07-05 09:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-08 09:00'],
        [ActivityStatus::Done, '2026-07-09 09:00'],
        // E ainda reabre uma vez antes de fechar de vez.
        [ActivityStatus::Doing, '2026-07-10 09:00'],
        [ActivityStatus::Done, '2026-07-11 09:00'],
    ]);

    expect($epic->spec_entregue->toDateTimeString())->toBe('2026-07-08 09:00:00')
        ->and($epic->spec_validada->toDateTimeString())->toBe('2026-07-11 09:00:00');
});

test('a Spec sitting in the client hands right now is pending even after a previous approval', function () {
    $epic = withSpecHistory(
        Activity::factory()->epic()->awaitingApproval()->create(),
        [
            [ActivityStatus::AwaitingApproval, '2026-07-01 09:00'],
            [ActivityStatus::Todo, '2026-07-02 09:00'],
            // Escopo mudou: voltou para aprovação e é ali que está agora.
            [ActivityStatus::AwaitingApproval, '2026-07-06 09:00'],
        ]
    );

    expect($epic->spec_aprovada->toDateTimeString())->toBe('2026-07-02 09:00:00')
        ->and($epic->isSpecPending())->toBeTrue();
});

test('an approved Spec stops being pending', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->todo()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-01 09:00'],
        [ActivityStatus::Todo, '2026-07-02 09:00'],
    ]);

    expect($epic->isSpecPending())->toBeFalse();
});

test('a child is blocked only while its Épico Spec is pending', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->awaitingApproval()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-01 09:00'],
    ]);

    $child = Activity::factory()->issue()->todo()->create(['parent_id' => $epic->id]);
    $orphan = Activity::factory()->issue()->todo()->create();

    expect($child->isBlockedBySpecApproval())->toBeTrue()
        ->and($orphan->isBlockedBySpecApproval())->toBeFalse();

    withSpecHistory($epic, [
        [ActivityStatus::AwaitingApproval, '2026-07-01 09:00'],
        [ActivityStatus::Todo, '2026-07-03 09:00'],
    ]);
    $epic->update(['status' => ActivityStatus::Todo]);

    expect($child->fresh()->isBlockedBySpecApproval())->toBeFalse();
});

test('a child of an Épico that never used the approval flow is never blocked', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->doing()->create(), [
        [ActivityStatus::Backlog, '2026-07-01 09:00'],
        [ActivityStatus::Doing, '2026-07-02 09:00'],
    ]);

    $child = Activity::factory()->issue()->todo()->create(['parent_id' => $epic->id]);

    expect($child->isBlockedBySpecApproval())->toBeFalse();
});
