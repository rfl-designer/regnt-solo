<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('project detail route requires authentication', function () {
    auth()->logout();
    $project = Project::factory()->create();

    $this->get(route('project.detail', $project->slug))
        ->assertRedirect(route('login'));
});

test('project detail page renders for authenticated users', function () {
    $project = Project::factory()->create();

    $this->get(route('project.detail', $project->slug))
        ->assertOk();
});

test('project detail component renders with project data', function () {
    $project = Project::factory()->create([
        'name' => 'Meu Projeto',
        'emoji' => '🚀',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSuccessful()
        ->assertSee('🚀')
        ->assertSee('Meu Projeto');
});

test('project detail shows status and priority badges', function () {
    $project = Project::factory()->highPriority()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee($project->status->label())
        ->assertSee($project->priority->label());
});

test('project detail shows description when present', function () {
    $project = Project::factory()->create([
        'description' => 'Uma descrição detalhada do projeto',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Uma descrição detalhada do projeto');
});

test('project detail mini-kanban shows tasks by status', function () {
    $project = Project::factory()->create();
    Task::factory()->backlog()->create(['project_id' => $project->id, 'title' => 'Task Backlog']);
    Task::factory()->todo()->create(['project_id' => $project->id, 'title' => 'Task Todo']);
    Task::factory()->doing()->create(['project_id' => $project->id, 'title' => 'Task Doing']);
    Task::factory()->done()->create(['project_id' => $project->id, 'title' => 'Task Done']);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Task Backlog')
        ->assertSee('Task Todo')
        ->assertSee('Task Doing')
        ->assertSee('Task Done');
});

test('project detail metrics shows total tasks count', function () {
    $project = Project::factory()->create();
    Task::factory()->count(5)->todo()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $metrics = $component->get('metrics');
    expect($metrics['total'])->toBe(5);
});

test('project detail metrics shows completion percentage', function () {
    $project = Project::factory()->create();
    Task::factory()->count(3)->todo()->create(['project_id' => $project->id]);
    Task::factory()->count(2)->done()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $metrics = $component->get('metrics');
    expect($metrics['completion_percent'])->toBe(40.0);
});

test('project detail metrics shows zero percent when no tasks', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $metrics = $component->get('metrics');
    expect($metrics['completion_percent'])->toBe(0);
});

test('project detail shows archive button with danger styling for active projects', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSeeHtml('Arquivar')
        ->assertSeeHtml('text-red-400');
});

test('project detail shows archive confirmation modal when button clicked', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('showArchiveModal', true)
        ->assertSet('showArchiveModal', true)
        ->assertSee('Arquivar projeto')
        ->assertSee('Tem certeza que deseja arquivar');
});

test('project detail archive modal closes after archiving', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('showArchiveModal', true)
        ->call('archiveProject')
        ->assertSet('showArchiveModal', false);
});

test('project detail archive project changes status', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('archiveProject')
        ->assertDispatched('project-updated');

    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('project detail shows reactivate button instead of archive for archived projects', function () {
    $project = Project::factory()->archived()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Reativar')
        ->assertDontSeeHtml('wire:click="$set(\'showArchiveModal\', true)"');
});

test('project detail activate project changes status', function () {
    $project = Project::factory()->archived()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('activateProject')
        ->assertDispatched('project-updated');

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('project detail listens to task-created event', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->dispatch('task-created')
        ->assertSuccessful();
});

test('project detail listens to task-updated event', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->dispatch('task-updated')
        ->assertSuccessful();
});

test('project detail listens to project-updated event', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->dispatch('project-updated')
        ->assertSuccessful();
});

test('project detail returns 404 for non-existent slug', function () {
    $this->get(route('project.detail', 'non-existent-slug'))
        ->assertNotFound();
});

test('project detail shows kanban column headers', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Backlog')
        ->assertSee('A Fazer')
        ->assertSee('Fazendo')
        ->assertSee('Concluída');
});

test('project detail docs tab shows documents list', function () {
    $project = Project::factory()->create();
    $document = \App\Models\Document::factory()->create([
        'project_id' => $project->id,
        'title' => 'Meu Documento de Teste',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'docs')
        ->assertSee('Meu Documento de Teste');
});

test('project detail docs tab shows empty state when no documents', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'docs')
        ->assertSee('Nenhum documento neste projeto.');
});

test('project detail can select document for preview', function () {
    $project = Project::factory()->create();
    $document = \App\Models\Document::factory()->create([
        'project_id' => $project->id,
        'title' => 'Documento Preview',
        'content' => '# Conteúdo do documento',
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'docs')
        ->call('selectDocument', $document->id);

    expect($component->get('selectedDocumentId'))->toBe($document->id);
    $component->assertSee('Documento Preview');
});

test('project detail can clear document selection by setting to null', function () {
    $project = Project::factory()->create();
    $document = \App\Models\Document::factory()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('selectDocument', $document->id)
        ->set('selectedDocumentId', null);

    expect($component->get('selectedDocumentId'))->toBeNull();
});

test('project detail selectedDocument computed returns correct document', function () {
    $project = Project::factory()->create();
    $document = \App\Models\Document::factory()->create([
        'project_id' => $project->id,
        'title' => 'Documento Selecionado',
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('selectDocument', $document->id);

    $selectedDocument = $component->get('selectedDocument');
    expect($selectedDocument)->not->toBeNull();
    expect($selectedDocument->id)->toBe($document->id);
    expect($selectedDocument->title)->toBe('Documento Selecionado');
});

test('project detail selectedDocument computed returns null when no selection', function () {
    $project = Project::factory()->create();
    \App\Models\Document::factory()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    expect($component->get('selectedDocument'))->toBeNull();
});

test('project detail tasks are ordered by sort_order then created_at desc', function () {
    $project = Project::factory()->create();

    // Create tasks with same sort_order but different created_at
    $olderTask = Task::factory()->todo()->create([
        'project_id' => $project->id,
        'title' => 'Older Task',
        'sort_order' => 1,
        'created_at' => now()->subDays(2),
    ]);
    $newerTask = Task::factory()->todo()->create([
        'project_id' => $project->id,
        'title' => 'Newer Task',
        'sort_order' => 1,
        'created_at' => now(),
    ]);
    $priorityTask = Task::factory()->todo()->create([
        'project_id' => $project->id,
        'title' => 'Priority Task',
        'sort_order' => 0,
        'created_at' => now()->subDays(5),
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $tasksByStatus = $component->get('tasksByStatus');
    $todoTasks = $tasksByStatus['todo']->values();

    // Priority task comes first (sort_order = 0)
    expect($todoTasks[0]->id)->toBe($priorityTask->id);
    // Newer task comes before older task (same sort_order, desc created_at)
    expect($todoTasks[1]->id)->toBe($newerTask->id);
    expect($todoTasks[2]->id)->toBe($olderTask->id);
});

test('project detail getColumnTasks respects sort_order and created_at ordering', function () {
    $project = Project::factory()->create();

    $taskA = Task::factory()->backlog()->create([
        'project_id' => $project->id,
        'title' => 'Task A',
        'sort_order' => 1,
        'created_at' => now()->subHours(1),
    ]);
    $taskB = Task::factory()->backlog()->create([
        'project_id' => $project->id,
        'title' => 'Task B',
        'sort_order' => 1,
        'created_at' => now(),
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $columnTasks = $component->instance()->getColumnTasks(\App\Enums\TaskStatus::Backlog)->values();

    // Task B (newer) should come before Task A (older) when same sort_order
    expect($columnTasks[0]->id)->toBe($taskB->id);
    expect($columnTasks[1]->id)->toBe($taskA->id);
});

// ========================================
// Kanban Feature Parity Tests
// ========================================

test('project detail kanban has handleSort method', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    expect(method_exists($component->instance(), 'handleSort'))->toBeTrue();
});

test('project detail kanban handleSort moves task between columns', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->backlog()->create([
        'project_id' => $project->id,
        'title' => 'Task to Move',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('handleSort', $task->id, 0, 'todo');

    expect($task->fresh()->status)->toBe(\App\Enums\TaskStatus::Todo);
});

test('project detail kanban handleSort updates status when moving between columns', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->backlog()->create([
        'project_id' => $project->id,
        'sort_order' => 5,
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('handleSort', $task->id, 0, 'doing');

    expect($task->fresh()->status)->toBe(\App\Enums\TaskStatus::Doing);
});

test('project detail kanban handleSort marks task as done when moved to done column', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->doing()->create([
        'project_id' => $project->id,
        'title' => 'Task to Complete',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('handleSort', $task->id, 0, 'done');

    $task->refresh();
    expect($task->status)->toBe(\App\Enums\TaskStatus::Done);
    expect($task->completed_at)->not->toBeNull();
});

test('project detail kanban handleSort adds task to daily plan when completing', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->doing()->create([
        'project_id' => $project->id,
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('handleSort', $task->id, 0, 'done');

    $dailyPlan = \App\Models\DailyPlan::getOrCreateForDate(\Carbon\Carbon::today());
    expect($dailyPlan->tasks->contains($task))->toBeTrue();
});

test('project detail kanban loadMore increases limit by 10', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $initialLimit = $component->get('limits')['backlog'];
    expect($initialLimit)->toBe(10);

    $component->call('loadMore', 'backlog');

    expect($component->get('limits')['backlog'])->toBe(20);
});

test('project detail kanban getColumnTasks uses withCount commits', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->todo()->create([
        'project_id' => $project->id,
    ]);

    // Create commits for the task
    \App\Models\TaskCommit::factory()->count(3)->create([
        'task_id' => $task->id,
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $columnTasks = $component->instance()->getColumnTasks(\App\Enums\TaskStatus::Todo);

    $taskFromColumn = $columnTasks->firstWhere('id', $task->id);
    expect($taskFromColumn->commits_count)->toBe(3);
});

test('project detail kanban shows commits badge', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->todo()->create([
        'project_id' => $project->id,
        'title' => 'Task with Commits',
    ]);

    \App\Models\TaskCommit::factory()->count(2)->create([
        'task_id' => $task->id,
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('2 commits');
});

test('project detail kanban shows session badge for session tasks', function () {
    $project = Project::factory()->create();
    Task::factory()->session()->todo()->create([
        'project_id' => $project->id,
        'title' => 'Session Task',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('🤖 Sessão');
});

test('project detail kanban done column only shows tasks from current week', function () {
    $project = Project::factory()->create();

    // Task completed this week
    $currentWeekTask = Task::factory()->done()->create([
        'project_id' => $project->id,
        'title' => 'Current Week Task',
        'completed_at' => now(),
    ]);

    // Task completed last week
    $lastWeekTask = Task::factory()->done()->create([
        'project_id' => $project->id,
        'title' => 'Last Week Task',
        'completed_at' => now()->subWeek(),
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Current Week Task')
        ->assertDontSee('Last Week Task');
});

test('project detail kanban getColumnTotal filters done by current week', function () {
    $project = Project::factory()->create();

    // Create 3 tasks completed this week
    Task::factory()->count(3)->done()->create([
        'project_id' => $project->id,
        'completed_at' => now(),
    ]);

    // Create 2 tasks completed last week
    Task::factory()->count(2)->done()->create([
        'project_id' => $project->id,
        'completed_at' => now()->subWeek(),
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $total = $component->instance()->getColumnTotal(\App\Enums\TaskStatus::Done);

    expect($total)->toBe(3);
});

test('project detail kanban getColumnEstimate filters done by current week', function () {
    $project = Project::factory()->create();

    // Create task completed this week
    Task::factory()->done()->create([
        'project_id' => $project->id,
        'estimated_minutes' => 60,
        'completed_at' => now(),
    ]);

    // Create task completed last week
    Task::factory()->done()->create([
        'project_id' => $project->id,
        'estimated_minutes' => 120,
        'completed_at' => now()->subWeek(),
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $estimate = $component->instance()->getColumnEstimate(\App\Enums\TaskStatus::Done);

    expect($estimate)->toBe(60);
});

test('project detail kanban renders wire:sort attributes', function () {
    $project = Project::factory()->create();
    Task::factory()->todo()->create(['project_id' => $project->id]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSeeHtml('wire:sort="handleSort"')
        ->assertSeeHtml('wire:sort:group="project-tasks"');
});

test('project detail kanban renders grip handle for drag', function () {
    $project = Project::factory()->create();
    Task::factory()->todo()->create(['project_id' => $project->id]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSeeHtml('wire:sort:handle');
});

test('project detail kanban shows quick-add button per column', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSeeHtml("open-quick-add-with-status', { status: 'backlog' }")
        ->assertSeeHtml("open-quick-add-with-status', { status: 'todo' }")
        ->assertSeeHtml("open-quick-add-with-status', { status: 'doing' }");
});

test('project detail kanban shows color legend button', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Legenda');
});

test('project detail kanban task position updates correctly after handleSort', function () {
    $project = Project::factory()->create();

    $task1 = Task::factory()->backlog()->create([
        'project_id' => $project->id,
        'sort_order' => 0,
    ]);
    $task2 = Task::factory()->backlog()->create([
        'project_id' => $project->id,
        'sort_order' => 1,
    ]);

    // Move task1 to todo status
    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('handleSort', $task1->id, 0, 'todo');

    $task1->refresh();
    expect($task1->status)->toBe(\App\Enums\TaskStatus::Todo);
    expect($task1->sort_order)->toBe(0);
});
