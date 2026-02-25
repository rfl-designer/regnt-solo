<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatusChange;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReview;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ─── US-004: Page, Route, Navigation, Summary ───────────────────────

test('review route requires authentication', function () {
    auth()->logout();

    $this->get(route('review'))
        ->assertRedirect(route('login'));
});

test('review page renders correctly for authenticated users', function () {
    $this->get(route('review'))
        ->assertOk();
});

test('weekly review component renders successfully', function () {
    Livewire::test('pages::weekly-review')
        ->assertSuccessful()
        ->assertSee('Weekly Review');
});

test('weekly review defaults to current week', function () {
    $component = Livewire::test('pages::weekly-review');

    expect($component->get('week'))->toBe(now()->startOfWeek()->toDateString());
});

test('weekly review shows current week badge', function () {
    Livewire::test('pages::weekly-review')
        ->assertSee('Semana atual');
});

test('weekly review does not show current week badge for past weeks', function () {
    $lastWeek = now()->subWeek()->startOfWeek()->toDateString();

    Livewire::test('pages::weekly-review', ['week' => $lastWeek])
        ->assertDontSee('Semana atual');
});

test('weekly review navigates to previous week', function () {
    $currentWeekStart = now()->startOfWeek();

    Livewire::test('pages::weekly-review')
        ->call('previousWeek')
        ->assertSet('week', $currentWeekStart->subWeek()->toDateString());
});

test('weekly review navigates to next week', function () {
    $lastWeek = now()->subWeek()->startOfWeek()->toDateString();

    Livewire::test('pages::weekly-review', ['week' => $lastWeek])
        ->call('nextWeek')
        ->assertSet('week', now()->startOfWeek()->toDateString());
});

test('weekly review does not navigate past current week', function () {
    $currentWeek = now()->startOfWeek()->toDateString();

    Livewire::test('pages::weekly-review')
        ->call('nextWeek')
        ->assertSet('week', $currentWeek);
});

test('weekly review auto-generates review for selected week', function () {
    expect(WeeklyReview::count())->toBe(0);

    Livewire::test('pages::weekly-review');

    expect(WeeklyReview::count())->toBe(1);
});

test('weekly review auto-generation is idempotent', function () {
    Livewire::test('pages::weekly-review');
    Livewire::test('pages::weekly-review');

    expect(WeeklyReview::count())->toBe(1);
});

test('weekly review accepts week URL parameter', function () {
    $lastWeek = now()->subWeek()->startOfWeek()->toDateString();

    Livewire::test('pages::weekly-review', ['week' => $lastWeek])
        ->assertSet('week', $lastWeek);
});

test('weekly review shows week period in header', function () {
    $weekStart = now()->startOfWeek();
    $weekEnd = now()->endOfWeek();

    Livewire::test('pages::weekly-review')
        ->assertSee('Semana de '.$weekStart->format('d/m').' - '.$weekEnd->format('d/m'));
});

test('weekly review shows summary cards with completed tasks count', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    Task::withoutEvents(fn () => Task::factory()->done()->create([
        'completed_at' => now()->startOfWeek()->addDay(),
    ]));

    Livewire::test('pages::weekly-review')
        ->assertSee('Completadas');
});

test('weekly review shows summary cards with total hours', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $task = Task::withoutEvents(fn () => Task::factory()->create());
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->startOfWeek()->addHours(9),
        'stopped_at' => now()->startOfWeek()->addHours(11),
    ]);

    Livewire::test('pages::weekly-review')
        ->assertSee('Horas totais')
        ->assertSee('2h');
});

test('weekly review shows tasks created vs completed ratio', function () {
    Livewire::test('pages::weekly-review')
        ->assertSee('Criadas / Completadas');
});

test('weekly review handles invalid week parameter gracefully', function () {
    Livewire::test('pages::weekly-review', ['week' => 'invalid-date'])
        ->assertSet('week', now()->startOfWeek()->toDateString())
        ->assertSuccessful();
});

// ─── US-005: Metrics Sections ────────────────────────────────────────

test('weekly review shows hours by project section', function () {
    $project = Project::factory()->create(['name' => 'Projeto Alpha', 'emoji' => '🚀']);
    $task = Task::withoutEvents(fn () => Task::factory()->create(['project_id' => $project->id]));

    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->startOfWeek()->addHours(9),
        'stopped_at' => now()->startOfWeek()->addHours(11),
    ]);

    Livewire::test('pages::weekly-review')
        ->assertSee('Horas por Projeto')
        ->assertSee('Projeto Alpha')
        ->assertSee('🚀');
});

test('weekly review shows hours by project empty state', function () {
    Livewire::test('pages::weekly-review')
        ->assertSee('Nenhum registro de tempo nesta semana');
});

test('weekly review shows completed tasks section with task details', function () {
    $project = Project::factory()->create(['name' => 'Projeto Beta', 'emoji' => '📦']);
    $task = Task::withoutEvents(fn () => Task::factory()->done()->create([
        'title' => 'Implementar feature X',
        'project_id' => $project->id,
        'completed_at' => now()->startOfWeek()->addDay(),
    ]));

    Livewire::test('pages::weekly-review')
        ->assertSee('Tasks Completadas')
        ->assertSee('Implementar feature X')
        ->assertSee('Projeto Beta')
        ->assertSee('📦');
});

test('weekly review shows completed tasks empty state', function () {
    Livewire::test('pages::weekly-review')
        ->assertSee('Nenhuma task completada nesta semana');
});

test('weekly review shows stale tasks section', function () {
    $task = Task::withoutEvents(fn () => Task::factory()->todo()->create([
        'title' => 'Task parada',
    ]));

    Livewire::test('pages::weekly-review')
        ->assertSee('Atencao Necessaria')
        ->assertSee('Task parada');
});

test('weekly review shows stale tasks positive empty state', function () {
    // Create a task with status change in the week so it's not stale
    $task = Task::withoutEvents(fn () => Task::factory()->doing()->create());
    TaskStatusChange::factory()->create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Todo,
        'to_status' => TaskStatus::Doing,
        'changed_at' => now()->startOfWeek()->addDay(),
    ]);

    Livewire::test('pages::weekly-review')
        ->assertSee('Todas as tasks tiveram progresso!');
});

test('weekly review shows task priority badge in completed tasks', function () {
    Task::withoutEvents(fn () => Task::factory()->done()->create([
        'title' => 'Task urgente',
        'priority' => 'urgent',
        'completed_at' => now()->startOfWeek()->addDay(),
    ]));

    Livewire::test('pages::weekly-review')
        ->assertSee('Task urgente')
        ->assertSee('Urgente');
});

test('weekly review shows task status badge in stale tasks', function () {
    Task::withoutEvents(fn () => Task::factory()->todo()->create([
        'title' => 'Task em todo',
    ]));

    Livewire::test('pages::weekly-review')
        ->assertSee('Task em todo')
        ->assertSee('A Fazer');
});

test('weekly review shows tracked time on completed tasks', function () {
    $task = Task::withoutEvents(fn () => Task::factory()->done()->create([
        'title' => 'Task com tempo',
        'completed_at' => now()->startOfWeek()->addDay(),
    ]));

    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->startOfWeek()->addHours(9),
        'stopped_at' => now()->startOfWeek()->addHours(10)->addMinutes(30),
    ]);

    Livewire::test('pages::weekly-review')
        ->assertSee('Task com tempo')
        ->assertSee('1h 30m');
});

test('weekly review handles tasks without project in hours by project', function () {
    $task = Task::withoutEvents(fn () => Task::factory()->create(['project_id' => null]));

    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->startOfWeek()->addHours(9),
        'stopped_at' => now()->startOfWeek()->addHours(10),
    ]);

    Livewire::test('pages::weekly-review')
        ->assertSee('Sem Projeto');
});

// ─── US-006: Reflection and History ──────────────────────────────────

test('weekly review shows reflection section', function () {
    Livewire::test('pages::weekly-review')
        ->assertSee('Reflexao')
        ->assertSee('O que funcionou bem esta semana? O que pode melhorar?');
});

test('weekly review saves reflection on blur', function () {
    $component = Livewire::test('pages::weekly-review')
        ->set('reflection', 'Esta semana foi produtiva!');

    $review = WeeklyReview::query()->forWeek(now())->first();

    expect($review->reflection)->toBe('Esta semana foi produtiva!');
});

test('weekly review persists reflection between navigations', function () {
    $review = WeeklyReview::factory()->thisWeek()->withReflection()->create();

    $component = Livewire::test('pages::weekly-review');

    expect($component->get('reflection'))->toBe($review->reflection);
});

test('weekly review shows history section', function () {
    Livewire::test('pages::weekly-review')
        ->assertSee('Historico');
});

test('weekly review shows previous reviews in history', function () {
    $lastWeek = WeeklyReview::factory()->lastWeek()->create();

    Livewire::test('pages::weekly-review')
        ->assertSee($lastWeek->week_start->format('d/m').' - '.$lastWeek->week_end->format('d/m'));
});

test('weekly review shows history empty state when no previous reviews', function () {
    Livewire::test('pages::weekly-review')
        ->assertSee('Nenhum review anterior encontrado');
});

test('weekly review navigates to week via history', function () {
    $lastWeek = WeeklyReview::factory()->lastWeek()->create();

    Livewire::test('pages::weekly-review')
        ->call('goToWeek', $lastWeek->week_start->toDateString())
        ->assertSet('week', $lastWeek->week_start->toDateString());
});

test('sidebar contains review link', function () {
    $this->get(route('dashboard'))
        ->assertSee('Revisão');
});
