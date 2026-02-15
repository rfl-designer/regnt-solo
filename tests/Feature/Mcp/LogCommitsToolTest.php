<?php

use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\LogCommitsTool;
use App\Models\Task;
use App\Models\TaskCommit;

test('log-commits registers commits for a task', function () {
    $task = Task::factory()->create();

    $response = SoloBoardServer::tool(LogCommitsTool::class, [
        'task_id' => $task->id,
        'commits' => [
            [
                'hash' => 'abc1234567890',
                'message' => 'feat: add user model',
                'files_changed' => 3,
                'insertions' => 50,
                'deletions' => 10,
                'committed_at' => '2025-01-15 10:00:00',
            ],
            [
                'hash' => 'def9876543210',
                'message' => 'test: add user tests',
                'files_changed' => 1,
                'insertions' => 30,
                'deletions' => 0,
                'committed_at' => '2025-01-15 11:00:00',
            ],
        ],
    ]);

    $response->assertOk();
    $response->assertSee('"commits_logged": 2');
    $response->assertSee('"total_commits": 2');

    $this->assertDatabaseCount('task_commits', 2);
    $this->assertDatabaseHas('task_commits', [
        'task_id' => $task->id,
        'hash' => 'abc1234567890',
        'message' => 'feat: add user model',
    ]);
});

test('log-commits ignores duplicate commits by hash', function () {
    $task = Task::factory()->create();
    TaskCommit::factory()->create([
        'task_id' => $task->id,
        'hash' => 'existing_hash_123',
        'message' => 'old message',
    ]);

    $response = SoloBoardServer::tool(LogCommitsTool::class, [
        'task_id' => $task->id,
        'commits' => [
            [
                'hash' => 'existing_hash_123',
                'message' => 'updated message',
                'files_changed' => 5,
                'insertions' => 100,
                'deletions' => 20,
            ],
            [
                'hash' => 'new_hash_456',
                'message' => 'new commit',
            ],
        ],
    ]);

    $response->assertOk();

    $this->assertDatabaseCount('task_commits', 2);
    // The existing commit should be updated (upsert behavior)
    $this->assertDatabaseHas('task_commits', [
        'hash' => 'existing_hash_123',
        'message' => 'updated message',
    ]);
});

test('log-commits updates pr_url when provided', function () {
    $task = Task::factory()->create();

    $response = SoloBoardServer::tool(LogCommitsTool::class, [
        'task_id' => $task->id,
        'commits' => [
            [
                'hash' => 'abc123',
                'message' => 'feat: something',
            ],
        ],
        'pr_url' => 'https://github.com/user/repo/pull/42',
    ]);

    $response->assertOk();
    $response->assertSee('https:\/\/github.com\/user\/repo\/pull\/42');

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'pr_url' => 'https://github.com/user/repo/pull/42',
    ]);
});

test('log-commits fails for non-existent task', function () {
    $response = SoloBoardServer::tool(LogCommitsTool::class, [
        'task_id' => 999,
        'commits' => [
            ['hash' => 'abc', 'message' => 'test'],
        ],
    ]);

    $response->assertHasErrors();
});

test('log-commits fails without commits', function () {
    $task = Task::factory()->create();

    $response = SoloBoardServer::tool(LogCommitsTool::class, [
        'task_id' => $task->id,
        'commits' => [],
    ]);

    $response->assertHasErrors();
});

test('cascade delete removes commits when task is deleted', function () {
    $task = Task::factory()->create();
    TaskCommit::factory()->count(3)->create(['task_id' => $task->id]);

    $this->assertDatabaseCount('task_commits', 3);

    $task->delete();

    $this->assertDatabaseCount('task_commits', 0);
});

test('task commitCount returns correct count', function () {
    $task = Task::factory()->create();
    TaskCommit::factory()->count(5)->create(['task_id' => $task->id]);

    expect($task->commitCount())->toBe(5);
});

test('task totalFilesChanged returns correct sum', function () {
    $task = Task::factory()->create();
    TaskCommit::factory()->create(['task_id' => $task->id, 'files_changed' => 3]);
    TaskCommit::factory()->create(['task_id' => $task->id, 'files_changed' => 7]);

    expect($task->totalFilesChanged())->toBe(10);
});
