<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;

describe('TaskTemplate Model', function (): void {
    it('creates a task template', function (): void {
        $template = TaskTemplate::factory()->create([
            'name' => 'Code Review',
            'slug' => null,
            'description' => 'Review checklist',
            'default_priority' => TaskPriority::High,
            'default_estimated_minutes' => 30,
        ]);

        expect($template->name)->toBe('Code Review')
            ->and($template->slug)->toBe('code-review')
            ->and($template->default_priority)->toBe(TaskPriority::High)
            ->and($template->default_estimated_minutes)->toBe(30);
    });

    it('auto generates slug from name', function (): void {
        $template = TaskTemplate::factory()->create([
            'name' => 'Deploy Checklist',
            'slug' => null,
        ]);

        expect($template->slug)->toBe('deploy-checklist');
    });

    it('creates a task from template', function (): void {
        $template = TaskTemplate::factory()->create([
            'name' => 'Bug Investigation',
            'description' => '## Steps',
            'default_priority' => TaskPriority::High,
            'default_estimated_minutes' => 60,
        ]);

        $task = $template->createTask();

        expect($task)->toBeInstanceOf(Task::class)
            ->and($task->title)->toBe('Bug Investigation')
            ->and($task->description)->toBe('## Steps')
            ->and($task->priority)->toBe(TaskPriority::High)
            ->and($task->status)->toBe(TaskStatus::Inbox)
            ->and($task->task_template_id)->toBe($template->id)
            ->and($task->estimated_minutes)->toBe(60);
    });

    it('creates task with overrides', function (): void {
        $template = TaskTemplate::factory()->create([
            'name' => 'Generic Template',
            'default_priority' => TaskPriority::Medium,
        ]);

        $project = Project::factory()->create();

        $task = $template->createTask([
            'title' => 'Custom Title',
            'project_id' => $project->id,
            'priority' => TaskPriority::Urgent,
        ]);

        expect($task->title)->toBe('Custom Title')
            ->and($task->project_id)->toBe($project->id)
            ->and($task->priority)->toBe(TaskPriority::Urgent)
            ->and($task->task_template_id)->toBe($template->id);
    });

    it('scopes system templates', function (): void {
        TaskTemplate::factory()->system()->count(2)->create();
        TaskTemplate::factory()->count(3)->create();

        expect(TaskTemplate::system()->count())->toBe(2);
    });

    it('scopes custom templates', function (): void {
        TaskTemplate::factory()->system()->count(2)->create();
        TaskTemplate::factory()->count(3)->create();

        expect(TaskTemplate::custom()->count())->toBe(3);
    });

    it('template has many tasks', function (): void {
        $template = TaskTemplate::factory()->create();
        $task1 = $template->createTask();
        $task2 = $template->createTask();

        expect($template->tasks)->toHaveCount(2);
    });
});

describe('Task relationships', function (): void {
    it('task belongs to template', function (): void {
        $template = TaskTemplate::factory()->create();
        $task = $template->createTask();

        expect($task->taskTemplate)->toBeInstanceOf(TaskTemplate::class)
            ->and($task->taskTemplate->id)->toBe($template->id)
            ->and($task->isFromTemplate())->toBeTrue();
    });

    it('task without template returns false for isFromTemplate', function (): void {
        $task = Task::factory()->create();

        expect($task->isFromTemplate())->toBeFalse();
    });
});

describe('TaskTemplateSeeder', function (): void {
    it('seeds system templates', function (): void {
        $this->artisan('db:seed', ['--class' => 'TaskTemplateSeeder']);

        expect(TaskTemplate::system()->count())->toBeGreaterThanOrEqual(5);

        $codeReview = TaskTemplate::where('slug', 'code-review')->first();
        expect($codeReview)->not->toBeNull()
            ->and($codeReview->is_system)->toBeTrue();
    });
});

describe('Template Factory States', function (): void {
    it('creates code review template', function (): void {
        $template = TaskTemplate::factory()->codeReview()->create();

        expect($template->name)->toBe('Code Review')
            ->and($template->slug)->toBe('code-review')
            ->and($template->is_system)->toBeTrue()
            ->and($template->default_priority)->toBe(TaskPriority::High);
    });

    it('creates deploy checklist template', function (): void {
        $template = TaskTemplate::factory()->deployChecklist()->create();

        expect($template->name)->toBe('Deploy Checklist')
            ->and($template->slug)->toBe('deploy-checklist')
            ->and($template->default_priority)->toBe(TaskPriority::Urgent);
    });

    it('creates bug investigation template', function (): void {
        $template = TaskTemplate::factory()->bugInvestigation()->create();

        expect($template->name)->toBe('Bug Investigation')
            ->and($template->default_priority)->toBe(TaskPriority::High);
    });
});
