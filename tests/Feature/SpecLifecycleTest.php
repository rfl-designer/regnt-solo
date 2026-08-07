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

test('an Épico that never entered Aguardando aprovação is deliberately not gated', function () {
    // Documented decision (#124 / review of #146): the lifecycle only
    // constrains an Épico once it has *started*. Treating "never sent" as
    // "not approved" would gate every legacy Épico on the board — every
    // slice of every plan made before the Spec lifecycle existed — behind
    // an approval nobody was ever asked for.
    $legacy = withSpecHistory(Activity::factory()->epic()->doing()->create(), [
        [ActivityStatus::Backlog, '2026-07-01 09:00'],
        [ActivityStatus::Todo, '2026-07-02 09:00'],
        [ActivityStatus::Doing, '2026-07-03 09:00'],
    ]);

    expect($legacy->specStage())->toBeNull()
        ->and($legacy->isSpecPending())->toBeFalse()
        ->and($legacy->hasSpecLifecycle())->toBeFalse();
});

test('an approval and a re-send in the same second are ordered by the history, not by the clock', function (bool $approvalFirst) {
    $epic = Activity::factory()->epic()->create();

    ActivityStatusChange::query()->where('activity_id', $epic->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $epic->id,
        'to_status' => ActivityStatus::AwaitingApproval,
        'changed_at' => Carbon::parse('2026-07-01 09:00:00'),
    ]);

    // Both recorded in the same second: `changed_at` cannot tell them
    // apart, so only the insertion order can. Approval-then-resend leaves
    // the Spec pending; resend-then-approval leaves it approved.
    $moves = [
        ['from' => ActivityStatus::AwaitingApproval, 'to' => ActivityStatus::Todo],
        ['from' => ActivityStatus::Todo, 'to' => ActivityStatus::AwaitingApproval],
    ];

    foreach ($approvalFirst ? $moves : array_reverse($moves) as $move) {
        ActivityStatusChange::factory()->create([
            'activity_id' => $epic->id,
            'from_status' => $move['from'],
            'to_status' => $move['to'],
            'changed_at' => Carbon::parse('2026-07-02 09:00:00'),
        ]);
    }

    expect($epic->fresh()->isSpecPending())->toBe($approvalFirst);
})->with([
    'approval then re-send — still waiting on the client' => [true],
    're-send then approval — answered' => [false],
]);

test('a validated Épico that is reopened goes back to being work in progress', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->doing()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-01 09:00'],
        [ActivityStatus::Todo, '2026-07-02 09:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-04 09:00'],
        [ActivityStatus::Done, '2026-07-05 09:00'],
        [ActivityStatus::Doing, '2026-07-06 09:00'],
    ]);

    // The validation date is a fact and stays readable; the *stage* is not
    // "validada" any more, because the work is open again.
    expect($epic->spec_validada->toDateTimeString())->toBe('2026-07-05 09:00:00')
        ->and($epic->specStage())->toBe('aprovada')
        ->and($epic->isSpecValidated())->toBeFalse();
});

test('a reopened Épico delivered again is back on Entregue, not on Validada', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->awaitingValidation()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-01 09:00'],
        [ActivityStatus::Todo, '2026-07-02 09:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-04 09:00'],
        [ActivityStatus::Done, '2026-07-05 09:00'],
        [ActivityStatus::Doing, '2026-07-06 09:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-08 09:00'],
    ]);

    expect($epic->specStage())->toBe('entregue')
        ->and($epic->isSpecValidated())->toBeFalse();
});

test('a delivery rejected at validation goes back to Aprovada', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->doing()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-01 09:00'],
        [ActivityStatus::Todo, '2026-07-02 09:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-04 09:00'],
        [ActivityStatus::Doing, '2026-07-05 09:00'],
    ]);

    expect($epic->spec_entregue->toDateTimeString())->toBe('2026-07-04 09:00:00')
        ->and($epic->specStage())->toBe('aprovada');
});
