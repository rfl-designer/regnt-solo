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
