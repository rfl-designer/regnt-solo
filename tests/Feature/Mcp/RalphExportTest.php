<?php

use App\Console\Commands\RalphExportCommand;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\RalphExportTool;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\File;

// --- Artisan Command Tests ---

describe('RalphExportCommand', function () {
    test('generates prd.json with correct format from feature with tasks', function () {
        $project = Project::factory()->create(['name' => 'Test Project']);
        $feature = Feature::factory()->create([
            'title' => 'Auth Feature',
            'slug' => 'auth-feature',
            'spec' => 'Implement authentication',
            'project_id' => $project->id,
        ]);
        Task::factory()->create([
            'feature_id' => $feature->id,
            'title' => 'Login form',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::High,
            'session_prompt' => "## Critérios de Aceitação\n- [ ] Email field\n- [ ] Password field",
        ]);
        Task::factory()->create([
            'feature_id' => $feature->id,
            'title' => 'Logout button',
            'status' => TaskStatus::Inbox,
            'priority' => TaskPriority::Medium,
        ]);

        $outputDir = storage_path('testing/ralph-'.uniqid());

        $this->artisan('soloboard:ralph', [
            'feature_id' => $feature->id,
            '--output-dir' => $outputDir,
        ])->assertSuccessful();

        $prdPath = "{$outputDir}/prd.json";
        expect(File::exists($prdPath))->toBeTrue();

        $prd = json_decode(File::get($prdPath), true);

        expect($prd)
            ->toHaveKey('project', 'Auth Feature')
            ->toHaveKey('branchName', 'ralph/auth-feature')
            ->toHaveKey('description', 'Implement authentication')
            ->toHaveKey('maxIterations', 10)
            ->toHaveKey('userStories');

        expect($prd['userStories'])->toHaveCount(2);

        File::deleteDirectory($outputDir);
    });

    test('maps done tasks as passes true', function () {
        $feature = Feature::factory()->create();
        $doneTask = Task::factory()->done()->create([
            'feature_id' => $feature->id,
            'title' => 'Completed task',
        ]);
        $todoTask = Task::factory()->todo()->create([
            'feature_id' => $feature->id,
            'title' => 'Pending task',
        ]);

        $command = new RalphExportCommand;
        $prd = $command->buildPrd($feature->load('tasks'));

        $stories = collect($prd['userStories']);
        $doneStory = $stories->firstWhere('metadata.soloboard_task_id', $doneTask->id);
        $todoStory = $stories->firstWhere('metadata.soloboard_task_id', $todoTask->id);

        expect($doneStory['passes'])->toBeTrue();
        expect($todoStory['passes'])->toBeFalse();
    });

    test('maps priority correctly', function () {
        $feature = Feature::factory()->create();
        Task::factory()->create([
            'feature_id' => $feature->id,
            'priority' => TaskPriority::Urgent,
        ]);
        Task::factory()->create([
            'feature_id' => $feature->id,
            'priority' => TaskPriority::High,
        ]);
        Task::factory()->create([
            'feature_id' => $feature->id,
            'priority' => TaskPriority::Medium,
        ]);
        Task::factory()->create([
            'feature_id' => $feature->id,
            'priority' => TaskPriority::Low,
        ]);

        $command = new RalphExportCommand;
        $prd = $command->buildPrd($feature->load('tasks'));

        $priorities = collect($prd['userStories'])->pluck('priority')->sort()->values()->all();
        expect($priorities)->toBe([1, 2, 3, 4]);
    });

    test('feature without tasks returns empty userStories', function () {
        $feature = Feature::factory()->create();

        $command = new RalphExportCommand;
        $prd = $command->buildPrd($feature->load('tasks'));

        expect($prd['userStories'])->toBeEmpty();
    });

    test('fails with nonexistent feature', function () {
        $this->artisan('soloboard:ralph', [
            'feature_id' => 99999,
        ])->assertFailed();
    });

    test('generates CLAUDE.md with MCP instructions', function () {
        $feature = Feature::factory()->create(['title' => 'Test Feature']);
        Task::factory()->create([
            'feature_id' => $feature->id,
            'title' => 'Test task',
        ]);

        $command = new RalphExportCommand;
        $claudeMd = $command->buildClaudeMd($feature->load('tasks'));

        expect($claudeMd)
            ->toContain('Test Feature')
            ->toContain('start-timer')
            ->toContain('stop-timer')
            ->toContain('update-task')
            ->toContain('COMPLETE')
            ->toContain('Test task');
    });

    test('dry-run does not create files', function () {
        $feature = Feature::factory()->create();
        Task::factory()->create(['feature_id' => $feature->id]);

        $outputDir = storage_path('testing/ralph-dryrun-'.uniqid());

        $this->artisan('soloboard:ralph', [
            'feature_id' => $feature->id,
            '--dry-run' => true,
            '--output-dir' => $outputDir,
        ])->assertSuccessful();

        expect(File::exists($outputDir))->toBeFalse();
    });

    test('generates progress.txt file', function () {
        $feature = Feature::factory()->create();
        Task::factory()->create(['feature_id' => $feature->id]);

        $outputDir = storage_path('testing/ralph-progress-'.uniqid());

        $this->artisan('soloboard:ralph', [
            'feature_id' => $feature->id,
            '--output-dir' => $outputDir,
        ])->assertSuccessful();

        expect(File::exists("{$outputDir}/progress.txt"))->toBeTrue();

        File::deleteDirectory($outputDir);
    });

    test('extracts acceptance criteria from session_prompt', function () {
        $feature = Feature::factory()->create();
        Task::factory()->create([
            'feature_id' => $feature->id,
            'session_prompt' => "## User Story\nAs a dev...\n\n## Critérios de Aceitação\n- [ ] First criterion\n- [ ] Second criterion\n\n## Notas",
        ]);

        $command = new RalphExportCommand;
        $prd = $command->buildPrd($feature->load('tasks'));

        $criteria = $prd['userStories'][0]['acceptanceCriteria'];
        expect($criteria)->toContain('First criterion')
            ->toContain('Second criterion')
            ->toHaveCount(2);
    });
});

// --- MCP Tool Tests ---

describe('RalphExportTool', function () {
    test('returns correct prd format', function () {
        $feature = Feature::factory()->create([
            'title' => 'MCP Feature',
            'slug' => 'mcp-feature',
            'spec' => 'MCP spec content',
        ]);
        Task::factory()->todo()->create([
            'feature_id' => $feature->id,
            'title' => 'MCP Task 1',
            'priority' => TaskPriority::High,
        ]);

        $response = SoloBoardServer::tool(RalphExportTool::class, [
            'feature_id' => $feature->id,
        ]);

        $response->assertOk();
        $response->assertSee('MCP Feature');
        $response->assertSee('ralph/mcp-feature');
        $response->assertSee('MCP Task 1');
        $response->assertSee('US-');
    });

    test('fails with nonexistent feature', function () {
        $response = SoloBoardServer::tool(RalphExportTool::class, [
            'feature_id' => 99999,
        ]);

        $response->assertHasErrors();
    });

    test('fails without feature_id', function () {
        $response = SoloBoardServer::tool(RalphExportTool::class, []);

        $response->assertHasErrors();
    });

    test('respects max_iterations parameter', function () {
        $feature = Feature::factory()->create();
        Task::factory()->create(['feature_id' => $feature->id]);

        $response = SoloBoardServer::tool(RalphExportTool::class, [
            'feature_id' => $feature->id,
            'max_iterations' => 5,
        ]);

        $response->assertOk();
        $response->assertSee('"maxIterations": 5');
    });
});
