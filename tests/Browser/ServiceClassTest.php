<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\EmergencyRequiresReasonException;
use App\Models\Activity;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * The service class select in the task modal is a native <select> rendered by
 * <flux:select wire:model="serviceClass">, so it is addressable by wire:model.
 */
$serviceClassSelect = '[role="tabpanel"] select[wire\\:model="serviceClass"]';

test('kanban card shows the service class badge', function (): void {
    Activity::factory()->issue()->emergency()->create([
        'title' => 'Task emergencial',
        'status' => ActivityStatus::Todo,
    ]);

    Activity::factory()->issue()->intangible()->create([
        'title' => 'Task intangivel',
        'status' => ActivityStatus::Todo,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Task emergencial')
        ->assertSee('Emergência')
        ->assertSee('Task intangivel')
        ->assertSee('Intangível');
});

test('task modal persists a new service class', function () use ($serviceClassSelect): void {
    $task = Activity::factory()->issue()->create([
        'title' => 'Task para reclassificar',
        'status' => ActivityStatus::Todo,
        'service_class' => ServiceClass::Standard,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Task para reclassificar')
        ->waitForText('Classe de serviço')
        ->assertSelected($serviceClassSelect, ServiceClass::Standard->value)
        ->select($serviceClassSelect, ServiceClass::Intangible->value)
        ->click('Salvar')
        // The save round trip has no unique text of its own (every service
        // class label already sits in the filter select), so settle the
        // Livewire request before reading the row back.
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($task->fresh()->service_class)->toBe(ServiceClass::Intangible);
});

test('task modal blocks classifying as Emergência until a motivo is typed (issue #143)', function () use ($serviceClassSelect): void {
    $task = Activity::factory()->issue()->create([
        'title' => 'Task que virou incêndio',
        'status' => ActivityStatus::Todo,
        'service_class' => ServiceClass::Standard,
    ]);

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Task que virou incêndio')
        ->waitForText('Classe de serviço')
        ->select($serviceClassSelect, ServiceClass::Emergency->value)
        ->waitForText('Motivo da Emergência')
        ->click('Salvar')
        ->waitForText(EmergencyRequiresReasonException::MESSAGE);

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);

    $page->fill('textarea[wire\\:model="emergencyReason"]', 'Produção fora do ar')
        ->click('Salvar')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($task->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->fresh()->emergency_reason)->toBe('Produção fora do ar');
});

test('task modal refuses data fixa without a due date and shows the PT-BR error', function () use ($serviceClassSelect): void {
    $task = Activity::factory()->issue()->create([
        'title' => 'Task sem prazo',
        'status' => ActivityStatus::Todo,
        'service_class' => ServiceClass::Standard,
        'due_date' => null,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Task sem prazo')
        ->waitForText('Classe de serviço')
        ->select($serviceClassSelect, ServiceClass::FixedDate->value)
        ->click('Salvar')
        ->waitForText('Classificar como Data fixa exige uma data de vencimento.')
        ->assertSee('Classificar como Data fixa exige uma data de vencimento.')
        // "Não foi possível salvar" is the toast heading only — the inline
        // amber hint never renders it, so this proves the toast fired.
        ->assertSee('Não foi possível salvar')
        ->assertPresent('[data-flux-toast-dialog][data-variant="danger"]');

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('kanban filters tasks by service class', function (): void {
    Activity::factory()->issue()->emergency()->create([
        'title' => 'Task emergencial filtrada',
        'status' => ActivityStatus::Todo,
    ]);

    Activity::factory()->issue()->intangible()->create([
        'title' => 'Task intangivel filtrada',
        'status' => ActivityStatus::Todo,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Task emergencial filtrada')
        ->assertSee('Task intangivel filtrada')
        ->select('select[wire\\:model\\.live="filterServiceClass"]', ServiceClass::Emergency->value)
        ->waitForText('Task emergencial filtrada')
        ->assertSee('Task emergencial filtrada')
        ->assertDontSee('Task intangivel filtrada');
});

test('main task surfaces load without javascript errors after the service class change', function (): void {
    Activity::factory()->issue()->emergency()->create([
        'title' => 'Task emergencial smoke',
        'status' => ActivityStatus::Todo,
    ]);

    Activity::factory()->issue()->fixedDate()->create([
        'title' => 'Task data fixa smoke',
        'status' => ActivityStatus::Doing,
    ]);

    Activity::factory()->issue()->intangible()->create([
        'title' => 'Task intangivel smoke',
        'status' => ActivityStatus::Inbox,
    ]);

    Activity::factory()->task()->create([
        'title' => 'Task pessoal smoke',
        'status' => ActivityStatus::Todo,
        'service_class' => ServiceClass::Standard,
    ]);

    visit(['/kanban', '/inbox', '/ritual', '/tasks'])
        ->assertNoJavaScriptErrors()
        ->assertNoBrokenImages();
});
