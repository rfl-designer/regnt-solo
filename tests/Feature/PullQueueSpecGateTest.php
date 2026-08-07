<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Services\PullQueueService;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * The Spec gate on the pull queue (issue #146): children of an Épico whose
 * Spec is still with the client don't get ranked, and therefore can't push
 * a committed item down Pronto. There is no hard lock — they stay on the
 * board, they just say why they aren't in the queue.
 */
function queueService(): PullQueueService
{
    return app(PullQueueService::class);
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * An Épico whose Spec was sent and never answered.
 */
function pendingEpic(): Activity
{
    return withSpecHistory(Activity::factory()->epic()->awaitingApproval()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 09:00'],
    ]);
}

/**
 * An Épico whose Spec was sent and approved.
 */
function approvedEpic(): Activity
{
    return withSpecHistory(Activity::factory()->epic()->todo()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 09:00'],
        [ActivityStatus::Todo, '2026-08-02 09:00'],
    ]);
}

test('a child of an Épico with a pending Spec is out of the queue', function () {
    $child = Activity::factory()->issue()->todo()->create(['parent_id' => pendingEpic()->id]);

    expect(queueService()->queue())->toBeEmpty()
        ->and(queueService()->blockedBySpec()->pluck('id')->all())->toBe([$child->id]);
});

test('approving the Spec puts the child back in the queue with no other move', function () {
    $epic = pendingEpic();
    $child = Activity::factory()->issue()->todo()->create(['parent_id' => $epic->id]);

    expect(queueService()->queue())->toBeEmpty();

    // The only thing that changes is the Épico's status. The child is
    // untouched — the gate is derived, not stamped on the child.
    $epic->update(['status' => ActivityStatus::Todo]);

    expect(queueService()->queue()->pluck('activity.id')->all())->toBe([$child->id])
        ->and(queueService()->blockedBySpec())->toBeEmpty();
});

test('a blocked child does not reorder Pronto, even as an Emergência arriving first', function () {
    // The blocked one entered Pronto first and is an Emergência, so on
    // every axis the queue orders by it would lead. It still doesn't rank:
    // nothing whose Spec hasn't been said yes to gets to push a committed
    // item down.
    $blocked = Activity::factory()->issue()->todo()->emergency()->create([
        'parent_id' => pendingEpic()->id,
    ]);
    withSpecHistory($blocked, [[ActivityStatus::Todo, '2026-08-01 09:00']]);

    $committed = Activity::factory()->issue()->todo()->create(['parent_id' => approvedEpic()->id]);
    withSpecHistory($committed, [[ActivityStatus::Todo, '2026-08-05 09:00']]);

    $loose = Activity::factory()->issue()->todo()->create();
    withSpecHistory($loose, [[ActivityStatus::Todo, '2026-08-06 09:00']]);

    expect(queueService()->queue()->pluck('activity.id')->all())
        ->toBe([$committed->id, $loose->id]);
});

test('a child of an Épico that never used the approval flow keeps its place', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->doing()->create(), [
        [ActivityStatus::Backlog, '2026-08-01 09:00'],
        [ActivityStatus::Doing, '2026-08-02 09:00'],
    ]);

    $child = Activity::factory()->issue()->todo()->create(['parent_id' => $epic->id]);

    expect(queueService()->queue()->pluck('activity.id')->all())->toBe([$child->id])
        ->and(queueService()->blockedBySpec())->toBeEmpty();
});

test('a reprovação puts the children back out of the queue', function () {
    $epic = approvedEpic();
    $child = Activity::factory()->issue()->todo()->create(['parent_id' => $epic->id]);

    expect(queueService()->queue())->toHaveCount(1);

    withSpecHistory($epic, [
        [ActivityStatus::AwaitingApproval, '2026-08-01 09:00'],
        [ActivityStatus::Todo, '2026-08-02 09:00'],
        [ActivityStatus::AwaitingApproval, '2026-08-04 09:00'],
        [ActivityStatus::Backlog, '2026-08-05 09:00'],
    ]);
    $epic->update(['status' => ActivityStatus::Backlog]);

    expect(queueService()->queue())->toBeEmpty()
        ->and(queueService()->blockedBySpec())->toHaveCount(1);
});

test('the Kanban still shows the blocked card, with the spec signal and outside the queue', function () {
    $blocked = Activity::factory()->issue()->todo()->create([
        'parent_id' => pendingEpic()->id,
        'title' => 'Fatia esperando a spec',
    ]);
    withSpecHistory($blocked, [[ActivityStatus::Todo, '2026-08-01 09:00']]);

    $committed = Activity::factory()->issue()->todo()->create([
        'parent_id' => approvedEpic()->id,
        'title' => 'Fatia aprovada',
    ]);
    withSpecHistory($committed, [[ActivityStatus::Todo, '2026-08-05 09:00']]);

    $response = Livewire::test('pages::kanban');

    // Both cards are on the board — the gate is about the order, not about
    // hiding work — and the blocked one comes last, under its own heading.
    $response
        ->assertSee('Fatia esperando a spec')
        ->assertSee('Fatia aprovada')
        ->assertSee('spec em aprovação')
        ->assertSeeInOrder(['Fatia aprovada', 'Spec em aprovação', 'Fatia esperando a spec']);
});
