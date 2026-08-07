<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * The Spec timeline in the Épico's modal (issue #146): four dates read off
 * the status history, and two shortcuts that do nothing but move the
 * status.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the timeline shows the four dates and lights the current step', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->awaitingValidation()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-02 09:00'],
        [ActivityStatus::Todo, '2026-07-05 09:00'],
        [ActivityStatus::AwaitingValidation, '2026-07-10 09:00'],
    ]);

    $component = Livewire::test('feature-modal')->call('open', $epic->id);

    $timeline = collect($component->get('specTimeline'))->keyBy('key');

    expect($timeline['enviada']['at']->toDateTimeString())->toBe('2026-07-02 09:00:00')
        ->and($timeline['aprovada']['at']->toDateTimeString())->toBe('2026-07-05 09:00:00')
        ->and($timeline['entregue']['at']->toDateTimeString())->toBe('2026-07-10 09:00:00')
        ->and($timeline['validada']['at'])->toBeNull()
        ->and($timeline['entregue']['current'])->toBeTrue()
        ->and($timeline['aprovada']['done'])->toBeTrue()
        ->and($timeline['validada']['current'])->toBeFalse();

    $component->assertSee('Ciclo da Spec')->assertSee('02/07/2026 09:00');
});

test('a Spec waiting on the client is lit on Enviada even after an earlier approval', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->awaitingApproval()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-07-02 09:00'],
        [ActivityStatus::Todo, '2026-07-05 09:00'],
        [ActivityStatus::AwaitingApproval, '2026-07-08 09:00'],
    ]);

    $timeline = collect(
        Livewire::test('feature-modal')->call('open', $epic->id)->get('specTimeline')
    )->keyBy('key');

    expect($timeline['enviada']['current'])->toBeTrue()
        ->and($timeline['aprovada']['current'])->toBeFalse();
});

test('the shortcuts only move the status, and the history is what records the event', function () {
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $epic = Activity::factory()->epic()->backlog()->create(['project_id' => $project->id]);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->call('sendForApproval');

    $epic->refresh();

    expect($epic->status)->toBe(ActivityStatus::AwaitingApproval)
        ->and($epic->spec_enviada)->not->toBeNull()
        ->and($epic->statusChanges()->where('to_status', ActivityStatus::AwaitingApproval)->count())->toBe(1);

    // Approve it by hand (a move like any other), then deliver.
    $epic->update(['status' => ActivityStatus::Doing]);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->call('deliverForValidation');

    $epic->refresh();

    expect($epic->status)->toBe(ActivityStatus::AwaitingValidation)
        ->and($epic->spec_aprovada)->not->toBeNull()
        ->and($epic->spec_entregue)->not->toBeNull();
});

test('an Épico with nobody to wait on is refused with a message instead of an exception', function () {
    $epic = Activity::factory()->epic()->backlog()->create(['project_id' => null, 'client_id' => null]);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->call('sendForApproval');

    expect($epic->fresh()->status)->toBe(ActivityStatus::Backlog);
});

test('the modal shows the flow efficiency once the Spec has been approved', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->done()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 12:00'],
        [ActivityStatus::Todo, '2026-08-03 12:00'],
        [ActivityStatus::Doing, '2026-08-04 12:00'],
        [ActivityStatus::Done, '2026-08-05 12:00'],
    ]);

    $component = Livewire::test('feature-modal')->call('open', $epic->id);

    // Two-day window, one day of Fazendo inside it.
    expect($component->get('specEfficiency')['percent'])->toBe(50);

    $component->assertSee('Eficiência de fluxo')->assertSee('50%');
});

test('a Spec never approved shows no efficiency at all', function () {
    $epic = withSpecHistory(Activity::factory()->epic()->awaitingApproval()->create(), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 12:00'],
    ]);

    $component = Livewire::test('feature-modal')->call('open', $epic->id);

    expect($component->get('specEfficiency'))->toBeNull();
    $component->assertDontSee('Eficiência de fluxo');
});
