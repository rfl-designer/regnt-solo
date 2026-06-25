<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('kanban page renders with done column collapse functionality', function () {
    // Create tasks in done status
    Activity::factory()->count(3)->create([
        'status' => ActivityStatus::Done,
        'completed_at' => now(),
    ]);

    $response = $this->get('/kanban');

    $response->assertStatus(200);
    $response->assertSee('Concluída');
    // Check Alpine.js data attributes for collapse
    $response->assertSee('doneCollapsed', false);
    $response->assertSee('toggleDoneColumn', false);
    $response->assertSee('kanban-done-collapsed', false);
});

test('kanban done column has collapse toggle button', function () {
    Activity::factory()->create([
        'status' => ActivityStatus::Done,
        'completed_at' => now(),
    ]);

    $response = $this->get('/kanban');

    $response->assertStatus(200);
    // Check for collapse button with title
    $response->assertSee('Colapsar coluna', false);
    // Check for @click handler
    $response->assertSee('@click.stop="toggleDoneColumn()"', false);
});

test('kanban done column displays task count in collapsed state', function () {
    $tasks = Activity::factory()->count(5)->create([
        'status' => ActivityStatus::Done,
        'completed_at' => now(),
    ]);

    $response = $this->get('/kanban');

    $response->assertStatus(200);
    // The badge with count should be visible in both states
    $response->assertSee((string) $tasks->count());
});

test('kanban done column has localStorage persistence attributes', function () {
    $response = $this->get('/kanban');

    $response->assertStatus(200);
    // Check for localStorage key (unescaped)
    $response->assertSee('kanban-done-collapsed', false);
    $response->assertSee('localStorage.getItem', false);
    $response->assertSee('localStorage.setItem', false);
});

test('kanban done column has transition animations', function () {
    $response = $this->get('/kanban');

    $response->assertStatus(200);
    // Check for transition classes
    $response->assertSee('transition-all duration-300', false);
    $response->assertSee('transition ease-out duration-200', false);
});

test('kanban done column width changes based on collapsed state', function () {
    $response = $this->get('/kanban');

    $response->assertStatus(200);
    // Check for dynamic width binding (responsive: w-72 sm:w-80)
    $response->assertSee("doneCollapsed ? 'w-14' : 'w-72 sm:w-80'", false);
});
