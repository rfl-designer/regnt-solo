<?php

declare(strict_types=1);

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('kanban column displays total estimate for tasks', function (): void {
    Activity::factory()->issue()->create([
        'status' => ActivityStatus::Todo,
        'estimated_minutes' => 60,
    ]);
    Activity::factory()->issue()->create([
        'status' => ActivityStatus::Todo,
        'estimated_minutes' => 90,
    ]);

    Livewire::test('pages::kanban')
        ->assertSee('2h 30m'); // 60 + 90 = 150 minutes
});

test('kanban column shows hours only when no remaining minutes', function (): void {
    Activity::factory()->issue()->create([
        'status' => ActivityStatus::Doing,
        'estimated_minutes' => 120, // exactly 2 hours
    ]);

    Livewire::test('pages::kanban')
        ->assertSee('2h');
});

test('kanban column shows minutes only when less than an hour', function (): void {
    Activity::factory()->issue()->create([
        'status' => ActivityStatus::Backlog,
        'estimated_minutes' => 45,
    ]);

    Livewire::test('pages::kanban')
        ->assertSee('45m');
});

test('kanban column hides estimate badge when no tasks have estimates', function (): void {
    Activity::factory()->issue()->create([
        'status' => ActivityStatus::Backlog,
        'estimated_minutes' => null,
    ]);

    // Should show the column but no estimate badge
    Livewire::test('pages::kanban')
        ->assertSee(ActivityStatus::Backlog->label())
        ->assertDontSee('0m')
        ->assertDontSee('0h');
});

test('getColumnEstimate returns sum of estimated_minutes', function (): void {
    Activity::factory()->issue()->create([
        'status' => ActivityStatus::Todo,
        'estimated_minutes' => 30,
    ]);
    Activity::factory()->issue()->create([
        'status' => ActivityStatus::Todo,
        'estimated_minutes' => 45,
    ]);
    Activity::factory()->issue()->create([
        'status' => ActivityStatus::Todo,
        'estimated_minutes' => null, // should be ignored
    ]);

    $component = Livewire::test('pages::kanban');

    expect($component->instance()->getColumnEstimate(ActivityStatus::Todo))->toBe(75);
});

test('formatDuration returns empty string for zero minutes', function (): void {
    $component = Livewire::test('pages::kanban');

    expect($component->instance()->formatDuration(0))->toBe('');
});

test('formatDuration handles various minute values correctly', function (int $minutes, string $expected): void {
    $component = Livewire::test('pages::kanban');

    expect($component->instance()->formatDuration($minutes))->toBe($expected);
})->with([
    [30, '30m'],
    [60, '1h'],
    [90, '1h 30m'],
    [120, '2h'],
    [150, '2h 30m'],
    [180, '3h'],
    [1, '1m'],
    [59, '59m'],
    [61, '1h 1m'],
]);
