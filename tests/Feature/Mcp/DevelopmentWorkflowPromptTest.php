<?php

use App\Enums\ActivityStatus;
use App\Mcp\Prompts\DevelopmentWorkflowPrompt;
use App\Mcp\Servers\SoloBoardServer;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Project;

test('development workflow prompt returns complete context for task', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->session()->todo()->create([
        'project_id' => $project->id,
    ]);

    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('Task para Implementar');
    $response->assertSee($task->title);
    $response->assertSee($project->name);
    $response->assertSee('INTAKE');
    $response->assertSee('start-timer');
});

test('development workflow prompt returns error for non-existent task', function () {
    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => '99999',
    ]);

    $response->assertOk();
    $response->assertSee('Task com ID `99999` não encontrada');
});

test('development workflow prompt includes related documents', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->session()->doing()->create([
        'project_id' => $project->id,
    ]);
    $document = Document::factory()->create([
        'project_id' => $project->id,
        'type' => 'prd',
    ]);

    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('Documentos Relacionados');
    $response->assertSee($document->title);
});

test('development workflow prompt includes related tasks', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->session()->doing()->create([
        'project_id' => $project->id,
    ]);
    $relatedTask = Activity::factory()->todo()->create([
        'project_id' => $project->id,
    ]);

    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('Tasks Relacionadas');
    $response->assertSee($relatedTask->title);
});

test('development workflow prompt detects livewire skills from session prompt', function () {
    $task = Activity::factory()->create([
        'session_prompt' => 'Criar componente Livewire com wire:model para formulário',
    ]);

    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('livewire-development');
});

test('development workflow prompt detects flux ui skills', function () {
    $task = Activity::factory()->create([
        'session_prompt' => 'Adicionar modal com flux:modal e tabela com flux:table',
    ]);

    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('fluxui-development');
});

test('development workflow prompt detects auth skills', function () {
    $task = Activity::factory()->create([
        'session_prompt' => 'Implementar 2FA no login do usuário',
    ]);

    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('developing-with-fortify');
});

test('development workflow prompt shows done task warning', function () {
    $task = Activity::factory()->create([
        'status' => ActivityStatus::Done,
    ]);

    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('já está concluída');
});

test('development workflow prompt includes today context', function () {
    $task = Activity::factory()->todo()->create();

    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('Contexto de Hoje');
    $response->assertSee('Timer ativo');
    $response->assertSee('Horas trabalhadas hoje');
});

test('development workflow prompt always suggests pest testing', function () {
    $task = Activity::factory()->create([
        'session_prompt' => 'Criar API endpoint simples',
    ]);

    $response = SoloBoardServer::prompt(DevelopmentWorkflowPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('pest-testing');
    $response->assertSee('Sempre obrigatório');
});
