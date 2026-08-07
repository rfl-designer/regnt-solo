<?php

use App\Enums\ActivityStatus;
use App\Exceptions\WaitingRequiresWaitingForException;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('moving to a client-side wait auto-fills waiting_for from the effective client via the project', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create(['project_id' => $project->id, 'status' => ActivityStatus::Todo]);

    $task->update(['status' => ActivityStatus::AwaitingApproval]);

    expect($task->waiting_for)->toBe('Acme Corp')
        ->and($task->waiting_since)->not->toBeNull();
});

test('moving to a client-side wait auto-fills waiting_for from a directly linked client', function () {
    $client = Client::factory()->create(['name' => 'Direct Client']);
    $task = Activity::factory()->create(['client_id' => $client->id, 'status' => ActivityStatus::Todo]);

    $task->update(['status' => ActivityStatus::AwaitingValidation]);

    expect($task->waiting_for)->toBe('Direct Client');
});

test('an explicit waiting_for is respected over the auto-fill', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create(['project_id' => $project->id, 'status' => ActivityStatus::Todo]);

    $task->update(['status' => ActivityStatus::AwaitingApproval, 'waiting_for' => 'Fulano de Tal']);

    expect($task->waiting_for)->toBe('Fulano de Tal');
});

test('moving to a client-side wait with no effective client is refused', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Todo]);

    expect(fn () => $task->update(['status' => ActivityStatus::AwaitingApproval]))
        ->toThrow(WaitingRequiresWaitingForException::class, WaitingRequiresWaitingForException::MESSAGE);
});

test('moving to the internal wait without waiting_for is refused, regardless of client', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create(['project_id' => $project->id, 'status' => ActivityStatus::Doing]);

    expect(fn () => $task->update(['status' => ActivityStatus::Waiting]))
        ->toThrow(WaitingRequiresWaitingForException::class);
});

test('moving to the internal wait with waiting_for provided succeeds', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Doing]);

    $task->update(['status' => ActivityStatus::Waiting, 'waiting_for' => 'Designer']);

    expect($task->waiting_for)->toBe('Designer')
        ->and($task->waiting_since)->not->toBeNull();
});

test('waiting_since is stamped once and does not move on subsequent saves in the same wait', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Doing]);
    $task->update(['status' => ActivityStatus::Waiting, 'waiting_for' => 'Designer']);
    $firstStamp = $task->fresh()->waiting_since;

    Carbon::setTestNow(now()->addDays(2));
    $task->update(['title' => 'Updated title while still waiting']);

    expect($task->fresh()->waiting_since->equalTo($firstStamp))->toBeTrue();
});

test('leaving a waiting status clears waiting_for and waiting_since automatically', function () {
    $task = Activity::factory()->waiting('Designer')->create();

    $task->update(['status' => ActivityStatus::Doing]);

    expect($task->waiting_for)->toBeNull()
        ->and($task->waiting_since)->toBeNull();
});

test('creating an activity directly in a waiting status without waiting_for is refused', function () {
    expect(fn () => Activity::factory()->create(['status' => ActivityStatus::Waiting]))
        ->toThrow(WaitingRequiresWaitingForException::class);
});

test('creating an activity directly in a waiting status with waiting_for succeeds', function () {
    $task = Activity::factory()->create([
        'status' => ActivityStatus::Waiting,
        'waiting_for' => 'Designer',
    ]);

    expect($task->waiting_for)->toBe('Designer')
        ->and($task->waiting_since)->not->toBeNull();
});

test('Activity::isWaiting and waitingDays reflect the current wait', function () {
    $task = Activity::factory()->waiting('Designer')->create();
    $task->forceFill(['waiting_since' => now()->subDays(3)])->saveQuietly();

    expect($task->fresh()->isWaiting())->toBeTrue()
        ->and($task->fresh()->waitingDays())->toBe(3);

    $task->update(['status' => ActivityStatus::Doing]);

    expect($task->fresh()->isWaiting())->toBeFalse()
        ->and($task->fresh()->waitingDays())->toBe(0);
});

test('scopeNotWaiting excludes activities in any waiting status', function () {
    Activity::factory()->create(['status' => ActivityStatus::Todo]);
    Activity::factory()->waiting()->create();
    Activity::factory()->awaitingApproval()->create();
    Activity::factory()->awaitingValidation()->create();

    expect(Activity::query()->notWaiting()->count())->toBe(1);
});

// -----------------------------------------------------------------------
// Finding 3: blank/whitespace waiting_for must be treated as absent.
// -----------------------------------------------------------------------

test('an empty string waiting_for is refused, same as null', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Doing]);

    expect(fn () => $task->update(['status' => ActivityStatus::Waiting, 'waiting_for' => '']))
        ->toThrow(WaitingRequiresWaitingForException::class);
});

test('a whitespace-only waiting_for is refused, same as null', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Doing]);

    expect(fn () => $task->update(['status' => ActivityStatus::Waiting, 'waiting_for' => '   ']))
        ->toThrow(WaitingRequiresWaitingForException::class);
});

test('a padded waiting_for is trimmed before being persisted', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Doing]);

    $task->update(['status' => ActivityStatus::Waiting, 'waiting_for' => '  Designer  ']);

    expect($task->fresh()->waiting_for)->toBe('Designer');
});

test('a whitespace-only waiting_for on a client-side wait still falls back to auto-fill', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create(['project_id' => $project->id, 'status' => ActivityStatus::Todo]);

    $task->update(['status' => ActivityStatus::AwaitingApproval, 'waiting_for' => '   ']);

    expect($task->fresh()->waiting_for)->toBe('Acme Corp');
});

// -----------------------------------------------------------------------
// Finding 4: wait-to-wait transitions must not silently reuse the
// previous wait's "esperando quem".
// -----------------------------------------------------------------------

test('moving from a client-side wait to the internal wait discards the inherited name and requires a fresh one', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Acme Corp',
    ]);

    expect(fn () => $task->update(['status' => ActivityStatus::Waiting]))
        ->toThrow(WaitingRequiresWaitingForException::class);

    expect($task->fresh()->status)->toBe(ActivityStatus::AwaitingApproval);
});

test('moving from a client-side wait to the internal wait succeeds with a fresh explicit name', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Acme Corp',
    ]);

    $task->update(['status' => ActivityStatus::Waiting, 'waiting_for' => 'Designer']);

    expect($task->fresh()->status)->toBe(ActivityStatus::Waiting)
        ->and($task->fresh()->waiting_for)->toBe('Designer');
});

test('moving from the internal wait to a client-side wait re-resolves the effective client instead of keeping the internal name', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::Waiting,
        'waiting_for' => 'Designer',
    ]);

    $task->update(['status' => ActivityStatus::AwaitingValidation]);

    expect($task->fresh()->waiting_for)->toBe('Acme Corp');
});

test('moving between two client-side waits keeps an explicitly provided name instead of overwriting it', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Acme Corp',
    ]);

    $task->update(['status' => ActivityStatus::AwaitingValidation, 'waiting_for' => 'Fulano de Tal']);

    expect($task->fresh()->waiting_for)->toBe('Fulano de Tal');
});

test('re-selecting the same wait status the task already had does not require a fresh waiting_for', function () {
    $task = Activity::factory()->waiting('Designer')->create();

    $task->update(['status' => ActivityStatus::Waiting, 'title' => 'Unrelated edit']);

    expect($task->fresh()->waiting_for)->toBe('Designer');
});

// -----------------------------------------------------------------------
// Finding 5: reassigning project/client during a client-side wait must
// re-resolve "esperando quem" instead of keeping the stale name.
// -----------------------------------------------------------------------

test('reassigning the project while already in a client-side wait re-resolves the effective client', function () {
    $oldClient = Client::factory()->create(['name' => 'Old Client']);
    $oldProject = Project::factory()->create(['client_id' => $oldClient->id]);
    $newClient = Client::factory()->create(['name' => 'New Client']);
    $newProject = Project::factory()->create(['client_id' => $newClient->id]);

    $task = Activity::factory()->create([
        'project_id' => $oldProject->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Old Client',
    ]);

    $task->update(['project_id' => $newProject->id]);

    expect($task->fresh()->waiting_for)->toBe('New Client');
});

test('reassigning the direct client while already in a client-side wait re-resolves the effective client', function () {
    $oldClient = Client::factory()->create(['name' => 'Old Client']);
    $newClient = Client::factory()->create(['name' => 'New Client']);

    $task = Activity::factory()->create([
        'client_id' => $oldClient->id,
        'status' => ActivityStatus::AwaitingValidation,
        'waiting_for' => 'Old Client',
    ]);

    $task->update(['client_id' => $newClient->id]);

    expect($task->fresh()->waiting_for)->toBe('New Client');
});

test('removing the effective client while already in a client-side wait is refused', function () {
    $client = Client::factory()->create(['name' => 'Old Client']);
    $project = Project::factory()->create(['client_id' => $client->id]);

    $task = Activity::factory()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Old Client',
    ]);

    expect(fn () => $task->update(['project_id' => null]))
        ->toThrow(WaitingRequiresWaitingForException::class);
});

test('reassigning the project does not overwrite a waiting_for explicitly changed in the same save', function () {
    $oldClient = Client::factory()->create(['name' => 'Old Client']);
    $oldProject = Project::factory()->create(['client_id' => $oldClient->id]);
    $newProject = Project::factory()->create();

    $task = Activity::factory()->create([
        'project_id' => $oldProject->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Old Client',
    ]);

    $task->update(['project_id' => $newProject->id, 'waiting_for' => 'Manually set name']);

    expect($task->fresh()->waiting_for)->toBe('Manually set name');
});

test('editing unrelated fields while in a client-side wait does not touch the stored waiting_for', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);

    $task = Activity::factory()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Fulano de Tal',
    ]);

    $task->update(['title' => 'Just a title edit']);

    expect($task->fresh()->waiting_for)->toBe('Fulano de Tal');
});

// -----------------------------------------------------------------------
// Finding 7: leaving a wait for a null status must also clear the fields.
// -----------------------------------------------------------------------

test('saving a null status while waiting clears waiting_for and waiting_since', function () {
    $task = Activity::factory()->waiting('Designer')->create();

    $task->status = null;
    $task->save();

    expect($task->fresh()->waiting_for)->toBeNull()
        ->and($task->fresh()->waiting_since)->toBeNull();
});

// -----------------------------------------------------------------------
// Finding 8: waiting_since must not be forgeable via mass assignment, and
// is always stamped fresh with now() on a genuine entry into a wait.
// -----------------------------------------------------------------------

test('waiting_since is not mass assignable on create', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $task = Activity::factory()->create([
        'status' => ActivityStatus::Waiting,
        'waiting_for' => 'Designer',
        // A caller-forged timestamp, deliberately far from "now" — mass
        // assignment must silently drop this, and the observer must stamp
        // the real entry time instead.
        'waiting_since' => Carbon::parse('1999-01-01'),
    ]);

    expect($task->fresh()->waiting_since->equalTo(Carbon::parse('2026-06-01 12:00:00')))->toBeTrue();
});

test('waiting_since is not mass assignable on update and is stamped fresh on entry regardless', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Doing]);

    Carbon::setTestNow('2020-01-01 00:00:00');

    $task->update([
        'status' => ActivityStatus::Waiting,
        'waiting_for' => 'Designer',
        'waiting_since' => Carbon::parse('1999-01-01'),
    ]);

    expect($task->fresh()->waiting_since->equalTo(Carbon::parse('2020-01-01 00:00:00')))->toBeTrue();
});
