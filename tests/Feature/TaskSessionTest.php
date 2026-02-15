<?php

use App\Models\Task;
use App\Models\TaskCommit;

test('task without session_prompt returns isSessionTask false', function () {
    $task = Task::factory()->create(['session_prompt' => null]);

    expect($task->isSessionTask())->toBeFalse();
});

test('task with session_prompt returns isSessionTask true', function () {
    $task = Task::factory()->create(['session_prompt' => 'Implementar feature X']);

    expect($task->isSessionTask())->toBeTrue();
});

test('sessionSummary returns correct array structure', function () {
    $task = Task::factory()->doing()->create([
        'session_prompt' => 'Implementar autenticação JWT',
        'session_result' => 'Autenticação implementada com sucesso',
        'pr_url' => 'https://github.com/org/repo/pull/42',
    ]);

    TaskCommit::factory()->count(3)->create([
        'task_id' => $task->id,
        'files_changed' => 5,
    ]);

    $summary = $task->sessionSummary();

    expect($summary)
        ->toBeArray()
        ->toHaveKeys(['is_session', 'prompt', 'result', 'pr_url', 'commits_count', 'files_changed', 'status'])
        ->and($summary['is_session'])->toBeTrue()
        ->and($summary['prompt'])->toBe('Implementar autenticação JWT')
        ->and($summary['result'])->toBe('Autenticação implementada com sucesso')
        ->and($summary['pr_url'])->toBe('https://github.com/org/repo/pull/42')
        ->and($summary['commits_count'])->toBe(3)
        ->and($summary['files_changed'])->toBe(15)
        ->and($summary['status'])->toBe('doing');
});

test('session fields persist in database', function () {
    $task = Task::factory()->create([
        'session_prompt' => 'Criar módulo de relatórios',
        'session_result' => 'Módulo criado com 3 tipos de relatório',
    ]);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'session_prompt' => 'Criar módulo de relatórios',
        'session_result' => 'Módulo criado com 3 tipos de relatório',
    ]);

    $freshTask = Task::find($task->id);
    expect($freshTask->session_prompt)->toBe('Criar módulo de relatórios');
    expect($freshTask->session_result)->toBe('Módulo criado com 3 tipos de relatório');
});
