<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('Work Page Routing', function () {
    test('route /work responds 200', function () {
        $this->get('/work')
            ->assertOk()
            ->assertSee('Work');
    });

    test('route /features redirects to /work', function () {
        $this->get('/features')
            ->assertRedirect('/work');
    });

    test('work route requires authentication', function () {
        auth()->logout();

        $this->get(route('work'))
            ->assertRedirect(route('login'));
    });

    test('named route work resolves to /work', function () {
        expect(route('work'))->toContain('/work');
    });
});

describe('Work Page Sidebar', function () {
    test('sidebar renders Work label', function () {
        $this->get(route('work'))
            ->assertSee('Work');
    });

    test('sidebar does not render Features label as nav item', function () {
        $response = $this->get(route('work'));
        $content = $response->getContent();

        // The sidebar should have "Work" in the nav, not "Features"
        expect($content)->not->toContain('sidebar.item icon="squares-2x2"');
    });

    test('sidebar contains Work in Planejamento group', function () {
        $this->get(route('work'))
            ->assertSee('Planejamento')
            ->assertSee('Work');
    });
});

describe('Work Page View Mode Toggle', function () {
    test('defaults to kanban view mode', function () {
        Livewire::test('pages::features')
            ->assertSet('viewMode', 'kanban');
    });

    test('can switch to table view mode', function () {
        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSet('viewMode', 'table');
    });

    test('view mode persists in URL via query string', function () {
        $this->get(route('work', ['view' => 'table']))
            ->assertOk();

        Livewire::test('pages::features', ['viewMode' => 'table'])
            ->assertSet('viewMode', 'table');
    });

    test('kanban view shows kanban board', function () {
        $feature = Activity::factory()->epic()->create(['title' => 'Kanban Test Feature', 'status' => ActivityStatus::Todo]);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->assertSet('viewMode', 'kanban')
            ->assertSee('Kanban Test Feature');
    });

    test('table view shows table with features', function () {
        $feature = Activity::factory()->epic()->create(['title' => 'Table Test Feature']);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('Table Test Feature');
    });
});

describe('Work Page Table View Columns', function () {
    test('table view renders column headers', function () {
        Activity::factory()->epic()->create();

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('ID')
            ->assertSee('Status')
            ->assertSee('Prioridade')
            ->assertSee('Projeto')
            ->assertSee('Progresso')
            ->assertSee('Tempo')
            ->assertSee('Vencimento');
    });

    test('table view renders feature ID with #F- prefix', function () {
        $feature = Activity::factory()->epic()->create(['title' => 'ID Feature']);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('#F-'.$feature->id);
    });

    test('table view renders feature status badge', function () {
        $feature = Activity::factory()->epic()->create(['status' => ActivityStatus::Todo]);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('A Fazer');
    });

    test('table view renders feature priority', function () {
        $feature = Activity::factory()->epic()->create(['priority' => ActivityPriority::High]);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('Alta');
    });

    test('table view renders project name', function () {
        $project = Project::factory()->create(['name' => 'Table Project', 'emoji' => '🎯']);
        $feature = Activity::factory()->epic()->create(['project_id' => $project->id]);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('Table Project');
    });

    test('table view renders progress', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Done]);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('1/2');
    });

    test('table view renders due date', function () {
        $feature = Activity::factory()->epic()->create(['due_date' => '2026-03-15']);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('15/03/2026');
    });

    test('table view shows empty state when no features', function () {
        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('Nenhuma feature encontrada');
    });
});

describe('Work Page Table Sorting', function () {
    test('can sort table by column', function () {
        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->call('sort', 'title')
            ->assertSet('sortBy', 'title')
            ->assertSet('sortDirection', 'asc');
    });

    test('toggling same column reverses sort direction', function () {
        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->call('sort', 'title')
            ->assertSet('sortDirection', 'asc')
            ->call('sort', 'title')
            ->assertSet('sortDirection', 'desc');
    });

    test('switching to different column resets to asc', function () {
        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->call('sort', 'title')
            ->call('sort', 'title')
            ->assertSet('sortDirection', 'desc')
            ->call('sort', 'status')
            ->assertSet('sortBy', 'status')
            ->assertSet('sortDirection', 'asc');
    });
});

describe('Work Page Filters in Both Views', function () {
    test('project filter works in table view', function () {
        $project1 = Project::factory()->create(['name' => 'Alpha Project']);
        $project2 = Project::factory()->create(['name' => 'Beta Project']);

        $feature1 = Activity::factory()->epic()->create(['title' => 'Alpha Feature', 'project_id' => $project1->id]);
        Activity::factory()->create(['parent_id' => $feature1->id, 'status' => ActivityStatus::Todo]);

        $feature2 = Activity::factory()->epic()->create(['title' => 'Beta Feature', 'project_id' => $project2->id]);
        Activity::factory()->create(['parent_id' => $feature2->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->set('filterProject', (string) $project1->id)
            ->assertSee('Alpha Feature')
            ->assertDontSee('Beta Feature');
    });

    test('priority filter works in table view', function () {
        $highFeature = Activity::factory()->epic()->create([
            'title' => 'High Table Feature',
            'priority' => ActivityPriority::High,
        ]);
        Activity::factory()->create(['parent_id' => $highFeature->id, 'status' => ActivityStatus::Todo]);

        $lowFeature = Activity::factory()->epic()->create([
            'title' => 'Low Table Feature',
            'priority' => ActivityPriority::Low,
        ]);
        Activity::factory()->create(['parent_id' => $lowFeature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->set('filterPriority', 'high')
            ->assertSee('High Table Feature')
            ->assertDontSee('Low Table Feature');
    });

    test('overdue filter works in table view', function () {
        $overdueFeature = Activity::factory()->epic()->create([
            'title' => 'Overdue Table Feature',
            'due_date' => now()->subDays(5),
        ]);
        Activity::factory()->create(['parent_id' => $overdueFeature->id, 'status' => ActivityStatus::Todo]);

        $futureFeature = Activity::factory()->epic()->create([
            'title' => 'Future Table Feature',
            'due_date' => now()->addDays(30),
        ]);
        Activity::factory()->create(['parent_id' => $futureFeature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->set('filterOverdue', true)
            ->assertSee('Overdue Table Feature')
            ->assertDontSee('Future Table Feature');
    });
});

describe('Work Page Keyboard Shortcuts', function () {
    test('app layout contains Ctrl+F shortcut pointing to /work', function () {
        $response = $this->get(route('work'));
        $content = $response->getContent();

        expect($content)->toContain("Livewire.navigate('/work')");
    });
});

describe('Work Page Command Palette', function () {
    test('command palette shows Work label instead of Features', function () {
        Livewire::test('command-palette')
            ->assertSee('Work');
    });

    test('command palette does not show Features as shortcut hint', function () {
        Livewire::test('command-palette')
            ->assertDontSee('>Features<')
            ->assertDontSee('>Features </');
    });
});
