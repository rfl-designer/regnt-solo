<?php

use App\Enums\ActivityPriority;
use App\Models\Activity;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\TaskTemplate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

describe('Templates Page Access', function (): void {
    test('templates route requires authentication', function (): void {
        auth()->logout();

        $this->get(route('templates'))
            ->assertRedirect(route('login'));
    });

    test('templates page renders correctly for authenticated users', function (): void {
        $this->get(route('templates'))
            ->assertOk();
    });

    test('templates component renders successfully', function (): void {
        Livewire::test('pages::templates')
            ->assertSuccessful()
            ->assertSee('Templates e Recorrentes');
    });
});

describe('Templates Tab', function (): void {
    test('shows empty state when no templates exist', function (): void {
        Livewire::test('pages::templates')
            ->assertSee('Nenhum template');
    });

    test('lists existing templates', function (): void {
        TaskTemplate::factory()->create(['name' => 'Code Review']);
        TaskTemplate::factory()->create(['name' => 'Deploy Checklist']);

        Livewire::test('pages::templates')
            ->assertSee('Code Review')
            ->assertSee('Deploy Checklist');
    });

    test('shows system badge for system templates', function (): void {
        TaskTemplate::factory()->system()->create(['name' => 'System Template']);

        Livewire::test('pages::templates')
            ->assertSee('Sistema');
    });

    test('can open template form', function (): void {
        Livewire::test('pages::templates')
            ->call('openTemplateForm')
            ->assertSet('showTemplateForm', true)
            ->assertSet('editingTemplateId', null);
    });

    test('can create a new template', function (): void {
        Livewire::test('pages::templates')
            ->set('templateName', 'New Template')
            ->set('templateDescription', 'Template description')
            ->set('templatePriority', 'high')
            ->set('templateEstimatedMinutes', 45)
            ->call('saveTemplate')
            ->assertSet('showTemplateForm', false);

        $this->assertDatabaseHas('task_templates', [
            'name' => 'New Template',
            'slug' => 'new-template',
            'default_priority' => 'high',
            'default_estimated_minutes' => 45,
        ]);
    });

    test('can edit existing template', function (): void {
        $template = TaskTemplate::factory()->create(['name' => 'Old Name']);

        Livewire::test('pages::templates')
            ->call('openTemplateForm', $template->id)
            ->assertSet('editingTemplateId', $template->id)
            ->assertSet('templateName', 'Old Name')
            ->set('templateName', 'New Name')
            ->call('saveTemplate');

        $this->assertDatabaseHas('task_templates', [
            'id' => $template->id,
            'name' => 'New Name',
        ]);
    });

    test('cannot delete system templates', function (): void {
        $template = TaskTemplate::factory()->system()->create();

        Livewire::test('pages::templates')
            ->set('deletingTemplateId', $template->id)
            ->call('deleteTemplate');

        $this->assertDatabaseHas('task_templates', ['id' => $template->id]);
    });

    test('can delete custom templates', function (): void {
        $template = TaskTemplate::factory()->create();

        Livewire::test('pages::templates')
            ->call('confirmDeleteTemplate', $template->id)
            ->assertSet('showDeleteTemplateModal', true)
            ->call('deleteTemplate');

        $this->assertDatabaseMissing('task_templates', ['id' => $template->id]);
    });

    test('can use template to create task', function (): void {
        $template = TaskTemplate::factory()->create([
            'name' => 'Bug Fix',
            'default_priority' => ActivityPriority::High,
        ]);

        Livewire::test('pages::templates')
            ->call('useTemplate', $template->id)
            ->assertDispatched('task-created');

        $task = Activity::where('task_template_id', $template->id)->first();
        expect($task)->not->toBeNull()
            ->and($task->title)->toBe('Bug Fix')
            ->and($task->priority)->toBe(ActivityPriority::High);
    });
});

describe('Recurring Tasks Tab', function (): void {
    test('can switch to recurring tab', function (): void {
        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->assertSee('Nenhuma task recorrente');
    });

    test('lists existing recurring tasks', function (): void {
        RecurringTask::factory()->create(['title' => 'Daily Standup']);
        RecurringTask::factory()->create(['title' => 'Weekly Review']);

        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->assertSee('Daily Standup')
            ->assertSee('Weekly Review');
    });

    test('can create a new recurring task', function (): void {
        $project = Project::factory()->create();

        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->call('openRecurringForm')
            ->set('recurringTitle', 'Weekly Review')
            ->set('recurringFrequency', 'weekly')
            ->set('recurringDayOfWeek', 1)
            ->set('recurringPriority', 'medium')
            ->set('recurringProjectId', $project->id)
            ->call('saveRecurring')
            ->assertSet('showRecurringForm', false);

        $this->assertDatabaseHas('recurring_tasks', [
            'title' => 'Weekly Review',
            'frequency' => 'weekly',
            'day_of_week' => 1,
            'project_id' => $project->id,
        ]);
    });

    test('can edit recurring task', function (): void {
        $recurring = RecurringTask::factory()->create(['title' => 'Old Title']);

        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->call('openRecurringForm', $recurring->id)
            ->assertSet('recurringTitle', 'Old Title')
            ->set('recurringTitle', 'New Title')
            ->call('saveRecurring');

        $this->assertDatabaseHas('recurring_tasks', [
            'id' => $recurring->id,
            'title' => 'New Title',
        ]);
    });

    test('can toggle recurring task active status', function (): void {
        $recurring = RecurringTask::factory()->create(['is_active' => true]);

        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->call('toggleRecurringActive', $recurring->id);

        $recurring->refresh();
        expect($recurring->is_active)->toBeFalse();
    });

    test('can delete recurring task', function (): void {
        $recurring = RecurringTask::factory()->create();

        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->call('confirmDeleteRecurring', $recurring->id)
            ->assertSet('showDeleteRecurringModal', true)
            ->call('deleteRecurring');

        $this->assertDatabaseMissing('recurring_tasks', ['id' => $recurring->id]);
    });

    test('can run recurring task now', function (): void {
        $recurring = RecurringTask::factory()->create(['title' => 'Manual Run']);

        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->call('runRecurringNow', $recurring->id)
            ->assertDispatched('task-created');

        $task = Activity::where('recurring_task_id', $recurring->id)->first();
        expect($task)->not->toBeNull()
            ->and($task->title)->toBe('Manual Run');
    });

    test('shows frequency and project info', function (): void {
        $project = Project::factory()->create(['name' => 'My Project', 'emoji' => '🚀']);
        RecurringTask::factory()->weekly()->forProject($project)->create([
            'title' => 'Project Task',
        ]);

        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->assertSee('Project Task')
            ->assertSee('Semanal')
            ->assertSee('🚀 My Project');
    });

    test('shows due badge for pending recurring tasks', function (): void {
        RecurringTask::factory()->dueToday()->create(['title' => 'Due Task']);

        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->assertSee('Pendente');
    });
});

describe('Form Validation', function (): void {
    test('template name is required', function (): void {
        Livewire::test('pages::templates')
            ->set('templateName', '')
            ->call('saveTemplate')
            ->assertHasErrors(['templateName' => 'required']);
    });

    test('recurring title is required', function (): void {
        Livewire::test('pages::templates')
            ->set('tab', 'recurring')
            ->set('recurringTitle', '')
            ->call('saveRecurring')
            ->assertHasErrors(['recurringTitle' => 'required']);
    });
});
