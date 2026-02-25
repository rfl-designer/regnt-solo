<?php

use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('kanban due date filter defaults to empty string', function () {
    Livewire::test('pages::kanban')
        ->assertSet('filterDueDate', '');
});

test('kanban filters tasks due today', function () {
    Task::factory()->todo()->create([
        'title' => 'Due today task',
        'due_date' => today(),
    ]);
    Task::factory()->todo()->create([
        'title' => 'Due tomorrow task',
        'due_date' => today()->addDay(),
    ]);
    Task::factory()->todo()->create([
        'title' => 'No due date task',
        'due_date' => null,
    ]);

    Livewire::test('pages::kanban')
        ->set('filterDueDate', 'today')
        ->assertSee('Due today task')
        ->assertDontSee('Due tomorrow task')
        ->assertDontSee('No due date task');
});

test('kanban filters tasks due this week', function () {
    Task::factory()->todo()->create([
        'title' => 'Due this week task',
        'due_date' => today()->endOfWeek(),
    ]);
    Task::factory()->todo()->create([
        'title' => 'Due next week task',
        'due_date' => today()->addWeeks(2),
    ]);

    Livewire::test('pages::kanban')
        ->set('filterDueDate', 'week')
        ->assertSee('Due this week task')
        ->assertDontSee('Due next week task');
});

test('kanban filters tasks due in next 7 days', function () {
    Task::factory()->todo()->create([
        'title' => 'Due in 5 days',
        'due_date' => today()->addDays(5),
    ]);
    Task::factory()->todo()->create([
        'title' => 'Due in 15 days',
        'due_date' => today()->addDays(15),
    ]);

    Livewire::test('pages::kanban')
        ->set('filterDueDate', '7days')
        ->assertSee('Due in 5 days')
        ->assertDontSee('Due in 15 days');
});

test('kanban filters tasks due in next 30 days', function () {
    Task::factory()->todo()->create([
        'title' => 'Due in 20 days',
        'due_date' => today()->addDays(20),
    ]);
    Task::factory()->todo()->create([
        'title' => 'Due in 60 days',
        'due_date' => today()->addDays(60),
    ]);

    Livewire::test('pages::kanban')
        ->set('filterDueDate', '30days')
        ->assertSee('Due in 20 days')
        ->assertDontSee('Due in 60 days');
});

test('kanban due date filter works with project filter', function () {
    $project = \App\Models\Project::factory()->create();

    Task::factory()->todo()->create([
        'title' => 'Project task due today',
        'due_date' => today(),
        'project_id' => $project->id,
    ]);
    Task::factory()->todo()->create([
        'title' => 'Other task due today',
        'due_date' => today(),
        'project_id' => null,
    ]);

    Livewire::test('pages::kanban')
        ->set('filterDueDate', 'today')
        ->set('filterProject', (string) $project->id)
        ->assertSee('Project task due today')
        ->assertDontSee('Other task due today');
});

test('kanban due date filter works with priority filter', function () {
    Task::factory()->todo()->urgent()->create([
        'title' => 'Urgent due today',
        'due_date' => today(),
    ]);
    Task::factory()->todo()->create([
        'title' => 'Low due today',
        'due_date' => today(),
        'priority' => \App\Enums\TaskPriority::Low,
    ]);

    Livewire::test('pages::kanban')
        ->set('filterDueDate', 'today')
        ->set('filterPriority', \App\Enums\TaskPriority::Urgent->value)
        ->assertSee('Urgent due today')
        ->assertDontSee('Low due today');
});

test('kanban due date filter works with overdue filter', function () {
    Task::factory()->overdue()->create([
        'title' => 'Overdue task',
    ]);
    Task::factory()->todo()->create([
        'title' => 'Due today task',
        'due_date' => today(),
    ]);

    Livewire::test('pages::kanban')
        ->set('filterDueDate', 'today')
        ->set('filterOverdue', true)
        ->assertDontSee('Due today task')
        ->assertDontSee('Overdue task');
});

test('kanban due date filter is persisted in url', function () {
    Livewire::test('pages::kanban')
        ->set('filterDueDate', 'today')
        ->assertSet('filterDueDate', 'today');
});

test('kanban due date filter excludes tasks without due date', function () {
    Task::factory()->todo()->create([
        'title' => 'No due date',
        'due_date' => null,
    ]);

    Livewire::test('pages::kanban')
        ->set('filterDueDate', '30days')
        ->assertDontSee('No due date');
});

test('kanban shows all tasks when due date filter is empty', function () {
    Task::factory()->todo()->create([
        'title' => 'Task with date',
        'due_date' => today(),
    ]);
    Task::factory()->todo()->create([
        'title' => 'Task without date',
        'due_date' => null,
    ]);

    Livewire::test('pages::kanban')
        ->set('filterDueDate', '')
        ->assertSee('Task with date')
        ->assertSee('Task without date');
});
