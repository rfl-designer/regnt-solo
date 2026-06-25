<?php

use App\Console\Commands\RalphExportCommand;
use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\RalphExportTool;
use App\Models\Activity;
use App\Models\Project;
use Illuminate\Support\Facades\File;

// --- Artisan Command Tests ---

describe('RalphExportCommand', function () {
    test('generates prd.json with correct format from feature with tasks', function () {
        $project = Project::factory()->create(['name' => 'Test Project']);
        $feature = Activity::factory()->epic()->create([
            'title' => 'Auth Feature',
            'slug' => 'auth-feature',
            'spec' => 'Implement authentication',
            'project_id' => $project->id,
        ]);
        Activity::factory()->create([
            'parent_id' => $feature->id,
            'title' => 'Login form',
            'status' => ActivityStatus::Todo,
            'priority' => ActivityPriority::High,
            'session_prompt' => "## Critérios de Aceitação\n- [ ] Email field\n- [ ] Password field",
        ]);
        Activity::factory()->create([
            'parent_id' => $feature->id,
            'title' => 'Logout button',
            'status' => ActivityStatus::Inbox,
            'priority' => ActivityPriority::Medium,
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
        $feature = Activity::factory()->epic()->create();
        $doneTask = Activity::factory()->done()->create([
            'parent_id' => $feature->id,
            'title' => 'Completed task',
        ]);
        $todoTask = Activity::factory()->todo()->create([
            'parent_id' => $feature->id,
            'title' => 'Pending task',
        ]);

        $command = new RalphExportCommand;
        $prd = $command->buildPrd($feature->load('children'));

        $stories = collect($prd['userStories']);
        $doneStory = $stories->firstWhere('metadata.soloboard_task_id', $doneTask->id);
        $todoStory = $stories->firstWhere('metadata.soloboard_task_id', $todoTask->id);

        expect($doneStory['passes'])->toBeTrue();
        expect($todoStory['passes'])->toBeFalse();
    });

    test('maps priority correctly', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->create([
            'parent_id' => $feature->id,
            'priority' => ActivityPriority::Urgent,
        ]);
        Activity::factory()->create([
            'parent_id' => $feature->id,
            'priority' => ActivityPriority::High,
        ]);
        Activity::factory()->create([
            'parent_id' => $feature->id,
            'priority' => ActivityPriority::Medium,
        ]);
        Activity::factory()->create([
            'parent_id' => $feature->id,
            'priority' => ActivityPriority::Low,
        ]);

        $command = new RalphExportCommand;
        $prd = $command->buildPrd($feature->load('children'));

        $priorities = collect($prd['userStories'])->pluck('priority')->sort()->values()->all();
        expect($priorities)->toBe([1, 2, 3, 4]);
    });

    test('feature without tasks returns empty userStories', function () {
        $feature = Activity::factory()->epic()->create();

        $command = new RalphExportCommand;
        $prd = $command->buildPrd($feature->load('children'));

        expect($prd['userStories'])->toBeEmpty();
    });

    test('fails with nonexistent feature', function () {
        $this->artisan('soloboard:ralph', [
            'feature_id' => 99999,
        ])->assertFailed();
    });

    test('generates CLAUDE.md with MCP instructions', function () {
        $feature = Activity::factory()->epic()->create(['title' => 'Test Feature']);
        Activity::factory()->create([
            'parent_id' => $feature->id,
            'title' => 'Test task',
        ]);

        $command = new RalphExportCommand;
        $claudeMd = $command->buildClaudeMd($feature->load('children'));

        expect($claudeMd)
            ->toContain('Test Feature')
            ->toContain('start-timer')
            ->toContain('stop-timer')
            ->toContain('update-task')
            ->toContain('COMPLETE')
            ->toContain('Test task');
    });

    test('dry-run does not create files', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->create(['parent_id' => $feature->id]);

        $outputDir = storage_path('testing/ralph-dryrun-'.uniqid());

        $this->artisan('soloboard:ralph', [
            'feature_id' => $feature->id,
            '--dry-run' => true,
            '--output-dir' => $outputDir,
        ])->assertSuccessful();

        expect(File::exists($outputDir))->toBeFalse();
    });

    test('generates progress.txt file', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->create(['parent_id' => $feature->id]);

        $outputDir = storage_path('testing/ralph-progress-'.uniqid());

        $this->artisan('soloboard:ralph', [
            'feature_id' => $feature->id,
            '--output-dir' => $outputDir,
        ])->assertSuccessful();

        expect(File::exists("{$outputDir}/progress.txt"))->toBeTrue();

        File::deleteDirectory($outputDir);
    });

    test('extracts acceptance criteria from session_prompt', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->create([
            'parent_id' => $feature->id,
            'session_prompt' => "## User Story\nAs a dev...\n\n## Critérios de Aceitação\n- [ ] First criterion\n- [ ] Second criterion\n\n## Notas",
        ]);

        $command = new RalphExportCommand;
        $prd = $command->buildPrd($feature->load('children'));

        $criteria = $prd['userStories'][0]['acceptanceCriteria'];
        expect($criteria)->toContain('First criterion')
            ->toContain('Second criterion')
            ->toHaveCount(2);
    });
});

// --- MCP Tool Tests ---

describe('RalphExportTool', function () {
    test('returns correct prd format', function () {
        $feature = Activity::factory()->epic()->create([
            'title' => 'MCP Feature',
            'slug' => 'mcp-feature',
            'spec' => 'MCP spec content',
        ]);
        Activity::factory()->todo()->create([
            'parent_id' => $feature->id,
            'title' => 'MCP Task 1',
            'priority' => ActivityPriority::High,
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
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->create(['parent_id' => $feature->id]);

        $response = SoloBoardServer::tool(RalphExportTool::class, [
            'feature_id' => $feature->id,
            'max_iterations' => 5,
        ]);

        $response->assertOk();
        $response->assertSee('"maxIterations": 5');
    });
});
