<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('available tasks excludes activities currently in a waiting status', function () {
    $available = Activity::factory()->todo()->create();
    $waiting = Activity::factory()->waiting()->create();
    $awaitingApproval = Activity::factory()->awaitingApproval()->create();
    $awaitingValidation = Activity::factory()->awaitingValidation()->create();

    $ids = Livewire::test('pages::daily-planner')
        ->get('availableTasks')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($available->id)
        ->and($ids)->not->toContain($waiting->id)
        ->and($ids)->not->toContain($awaitingApproval->id)
        ->and($ids)->not->toContain($awaitingValidation->id);
});

test('carry-over from yesterday excludes tasks currently in a waiting status', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);

    $normal = Activity::factory()->todo()->create(['title' => 'Task normal']);
    $waiting = Activity::factory()->waiting()->create(['title' => 'Task esperando']);

    $yesterdayPlan->tasks()->attach($normal, ['sort_order' => 0]);
    $yesterdayPlan->tasks()->attach($waiting, ['sort_order' => 1]);

    $ids = Livewire::test('pages::daily-planner')
        ->get('yesterdayIncompleteTasks')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($normal->id)
        ->and($ids)->not->toContain($waiting->id);
});

test('an item already in today\'s plan stays visible after entering a waiting status', function () {
    $task = Activity::factory()->todo()->create(['title' => 'Vai entrar em espera']);
    $plan = DailyPlan::getOrCreateForDate(Carbon::today());
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    $task->update(['status' => ActivityStatus::Waiting, 'waiting_for' => 'Designer']);

    Livewire::test('pages::daily-planner')
        ->assertSeeText('Vai entrar em espera')
        ->assertSeeText('Designer');
});

test('DailyPlan::incompleteTasks excludes waiting activities directly', function () {
    $plan = DailyPlan::factory()->create(['date' => Carbon::yesterday()->toDateString()]);

    $normal = Activity::factory()->todo()->create();
    $waiting = Activity::factory()->waiting()->create();

    $plan->tasks()->attach($normal, ['sort_order' => 0]);
    $plan->tasks()->attach($waiting, ['sort_order' => 1]);

    $incomplete = $plan->incompleteTasks();

    expect($incomplete->pluck('id')->all())->toBe([$normal->id]);
});
