<?php

use App\Enums\FeaturePriority;
use App\Enums\FeatureStatus;
use App\Enums\TaskStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
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
        $feature = Feature::factory()->create(['title' => 'Kanban Test Feature', 'status' => FeatureStatus::Todo]);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->assertSet('viewMode', 'kanban')
            ->assertSee('Kanban Test Feature');
    });

    test('table view shows table with features', function () {
        $feature = Feature::factory()->create(['title' => 'Table Test Feature']);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('Table Test Feature');
    });
});

describe('Work Page Table View Columns', function () {
    test('table view renders column headers', function () {
        Feature::factory()->create();

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
        $feature = Feature::factory()->create(['title' => 'ID Feature']);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('#F-'.$feature->id);
    });

    test('table view renders feature status badge', function () {
        $feature = Feature::factory()->create(['status' => FeatureStatus::Todo]);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('A Fazer');
    });

    test('table view renders feature priority', function () {
        $feature = Feature::factory()->create(['priority' => FeaturePriority::High]);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('Alta');
    });

    test('table view renders project name', function () {
        $project = Project::factory()->create(['name' => 'Table Project', 'emoji' => '🎯']);
        $feature = Feature::factory()->create(['project_id' => $project->id]);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('Table Project');
    });

    test('table view renders progress', function () {
        $feature = Feature::factory()->create();
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Done]);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->assertSee('1/2');
    });

    test('table view renders due date', function () {
        $feature = Feature::factory()->create(['due_date' => '2026-03-15']);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

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

        $feature1 = Feature::factory()->create(['title' => 'Alpha Feature', 'project_id' => $project1->id]);
        Task::factory()->create(['feature_id' => $feature1->id, 'status' => TaskStatus::Todo]);

        $feature2 = Feature::factory()->create(['title' => 'Beta Feature', 'project_id' => $project2->id]);
        Task::factory()->create(['feature_id' => $feature2->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->set('filterProject', (string) $project1->id)
            ->assertSee('Alpha Feature')
            ->assertDontSee('Beta Feature');
    });

    test('priority filter works in table view', function () {
        $highFeature = Feature::factory()->create([
            'title' => 'High Table Feature',
            'priority' => FeaturePriority::High,
        ]);
        Task::factory()->create(['feature_id' => $highFeature->id, 'status' => TaskStatus::Todo]);

        $lowFeature = Feature::factory()->create([
            'title' => 'Low Table Feature',
            'priority' => FeaturePriority::Low,
        ]);
        Task::factory()->create(['feature_id' => $lowFeature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->set('viewMode', 'table')
            ->set('filterPriority', 'high')
            ->assertSee('High Table Feature')
            ->assertDontSee('Low Table Feature');
    });

    test('overdue filter works in table view', function () {
        $overdueFeature = Feature::factory()->create([
            'title' => 'Overdue Table Feature',
            'due_date' => now()->subDays(5),
        ]);
        Task::factory()->create(['feature_id' => $overdueFeature->id, 'status' => TaskStatus::Todo]);

        $futureFeature = Feature::factory()->create([
            'title' => 'Future Table Feature',
            'due_date' => now()->addDays(30),
        ]);
        Task::factory()->create(['feature_id' => $futureFeature->id, 'status' => TaskStatus::Todo]);

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
