<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * The status select in the task modal is a native <select> rendered by
 * <flux:select wire:model.live="status">, scoped to the open tab panel so it
 * never collides with the board filters.
 */
$statusSelect = '[role="tabpanel"] select[wire\\:model\\.live="status"]';

/**
 * Each column renders one <ul wire:sort:group-id="{status}"> for the tasks
 * with a project and another for the "Sem projeto" section, so the DOM order
 * of the *distinct* group ids is the board's column order.
 */
$columnStatusesScript = <<<'JS'
function () {
    return [...new Set(
        Array.from(document.querySelectorAll('ul'))
            .filter(el => el.hasAttribute('wire:sort:group-id'))
            .map(el => el.getAttribute('wire:sort:group-id'))
    )].join(',');
}
JS;

/**
 * The per-column "+" button carries the column label in its title, one per
 * column, so it reads back the rendered PT-BR labels in board order.
 */
$columnLabelsScript = <<<'JS'
function () {
    return Array.from(document.querySelectorAll('[title^="Nova task em "]'))
        .map(el => el.getAttribute('title').replace('Nova task em ', ''))
        .join(',');
}
JS;

test('kanban renders the seven board columns in order with the new PT-BR labels', function () use ($columnStatusesScript, $columnLabelsScript): void {
    visit('/kanban')
        ->assertNoJavaScriptErrors()
        // Inbox stays off-board (triaged in /inbox), so it must not appear.
        ->assertScript($columnStatusesScript, 'backlog,awaiting_approval,todo,doing,waiting,awaiting_validation,done')
        ->assertScript($columnLabelsScript, 'Backlog,Aguardando aprovação,Pronto,Fazendo,Esperando,Aguardando validação,Feito');
});

test('kanban card shows the waiting badge with who is being waited on and for how long', function (): void {
    $awaiting = Activity::factory()->issue()->awaitingApproval('Acme Corp')->create([
        'title' => 'Task aguardando aprovacao',
    ]);

    $waiting = Activity::factory()->issue()->waiting('Designer')->create([
        'title' => 'Task esperando interno',
    ]);

    // The observer always re-stamps waiting_since on a genuine entry into a
    // wait, so an aged wait can only be simulated below the model layer.
    Activity::query()->whereKey($awaiting->id)->update(['waiting_since' => now()->subDays(3)]);
    Activity::query()->whereKey($waiting->id)->update(['waiting_since' => now()->subDay()]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Task aguardando aprovacao')
        ->assertSee('⏳ Acme Corp · há 3 dias')
        ->assertSee('Task esperando interno')
        ->assertSee('⏳ Designer · há 1 dia');
});

test('moving to the internal wait opens the blocking modal and persists the given name', function () use ($statusSelect): void {
    $task = Activity::factory()->issue()->create([
        'title' => 'Task para esperar',
        'status' => ActivityStatus::Doing,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Task para esperar')
        ->waitForText('Classe de serviço')
        // Picking the internal wait raises the blocking mini modal right
        // away — it is the only checkpoint that can supply "esperando quem"
        // for this status, so nothing is persisted until it is answered.
        ->select($statusSelect, ActivityStatus::Waiting->value)
        ->waitForText('Quem está sendo aguardado?')
        ->assertSee('Mover para "Esperando" exige informar quem está sendo aguardado.');

    expect($task->fresh()->status)->toBe(ActivityStatus::Doing)
        ->and($task->fresh()->waiting_for)->toBeNull();
});

test('confirming the blocking modal with a name completes the move to the internal wait', function () use ($statusSelect): void {
    $task = Activity::factory()->issue()->create([
        'title' => 'Task para esperar confirmada',
        'status' => ActivityStatus::Doing,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Task para esperar confirmada')
        ->waitForText('Classe de serviço')
        ->select($statusSelect, ActivityStatus::Waiting->value)
        ->waitForText('Quem está sendo aguardado?')
        // Three inputs bind "waitingFor" on this page: the task modal form,
        // the task modal's mini prompt, and the standalone drag-and-drop
        // modal. Placeholder + wire:model.live pins the mini prompt.
        ->type('input[wire\\:model\\.live="waitingFor"][placeholder="Ex: Designer, DevOps, Suporte..."]', 'DevOps')
        ->click('Confirmar e salvar')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Waiting)
        ->and($task->waiting_for)->toBe('DevOps')
        ->and($task->waiting_since)->not->toBeNull();
});

test('task modal shows the esperando quem field while the task is waiting', function (): void {
    $task = Activity::factory()->issue()->awaitingValidation('Globex Inc')->create([
        'title' => 'Task aguardando validacao',
    ]);

    Activity::query()->whereKey($task->id)->update(['waiting_since' => now()->subDays(2)]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Task aguardando validacao')
        ->waitForText('Esperando quem')
        ->assertSee('Esperando quem')
        ->assertValue('[role="tabpanel"] input[wire\\:model\\.live="waitingFor"]', 'Globex Inc')
        ->assertSee('⏳ Globex Inc · há 2 dias');
});

test('quick add from a waiting column lands in the inbox', function (): void {
    // A waiting column cannot take a brand new task (nobody is being waited
    // on yet), so the quick add falls back to the Inbox. Note the component
    // also fires a "Criada na Inbox" warning toast, but <flux:toast/> is a
    // singleton region here (no <flux:toast.group/>), so the success toast
    // dispatched right after replaces it before the user can read it — the
    // landing status below is the only part actually observable in browser.
    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('[title="Nova task em Esperando"]')
        ->waitForText('Nova Task')
        ->type('input[wire\\:model\\.live\\.debounce\\.150ms="rawInput"]', 'Task criada da coluna de espera')
        ->click('Criar Task')
        ->waitForText('Task criada')
        ->assertSee('Task criada da coluna de espera → Caixa de Entrada')
        ->assertNoJavaScriptErrors();

    $created = Activity::query()->where('title', 'Task criada da coluna de espera')->firstOrFail();

    expect($created->status)->toBe(ActivityStatus::Inbox)
        ->and($created->waiting_for)->toBeNull();
});

test('kanban and daily planner load without javascript errors with waiting activities present', function (): void {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);

    Activity::factory()->issue()->awaitingApproval('Acme Corp')->create([
        'title' => 'Espera de cliente smoke',
        'project_id' => $project->id,
    ]);

    Activity::factory()->issue()->waiting('Designer')->create([
        'title' => 'Espera interna smoke',
    ]);

    Activity::factory()->issue()->awaitingValidation('Acme Corp')->create([
        'title' => 'Validacao smoke',
        'project_id' => $project->id,
    ]);

    Activity::factory()->issue()->create([
        'title' => 'Task fazendo smoke',
        'status' => ActivityStatus::Doing,
    ]);

    visit(['/kanban', '/daily', '/inbox', '/tasks'])
        ->assertNoJavaScriptErrors()
        ->assertNoBrokenImages();
});
