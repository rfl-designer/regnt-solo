<?php

use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the modal collects the motivo and classifies the task', function () {
    $task = Activity::factory()->issue()->todo()->create();

    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $task->id)
        ->assertSet('step', 'reason')
        ->set('reason', 'Produção fora do ar')
        ->call('confirm')
        ->assertDispatched('task-updated');

    $task->refresh();

    expect($task->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->emergency_reason)->toBe('Produção fora do ar');
});

test('the modal refuses to confirm without a motivo', function () {
    $task = Activity::factory()->issue()->todo()->create();

    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $task->id)
        ->set('reason', '')
        ->call('confirm')
        ->assertHasErrors(['reason']);

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('a second Emergência opens the Manter/Substituir conflict step with the active one', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create([
        'title' => 'Hotfix do checkout',
    ]);
    $second = Activity::factory()->issue()->todo()->create();

    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $second->id)
        ->assertSet('step', 'conflict')
        ->assertSet('conflict.id', $active->id)
        ->assertSet('conflict.title', 'Hotfix do checkout')
        ->assertSet('conflict.reason', 'Incêndio atual')
        ->assertSee('Manter a atual')
        ->assertSee('Substituir');

    expect($second->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('Manter a atual leaves both classifications untouched', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create();
    $second = Activity::factory()->issue()->todo()->create();

    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $second->id)
        ->call('keepCurrent')
        ->assertSet('showModal', false);

    expect($active->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($second->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('Substituir demotes the active one to Padrão and only then classifies the new one', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio antigo')->create();
    $second = Activity::factory()->issue()->todo()->create();

    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $second->id)
        ->call('replaceCurrent')
        ->assertSet('step', 'reason')
        ->set('reason', 'Incêndio novo')
        ->call('confirm')
        ->assertDispatched('task-updated');

    expect($active->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($active->fresh()->emergency_reason)->toBeNull()
        ->and($second->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($second->fresh()->emergency_reason)->toBe('Incêndio novo');
});

test('Substituir with a blank motivo writes nothing at all — the demotion is chained to the promotion', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio antigo')->create();
    $second = Activity::factory()->issue()->todo()->create();

    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $second->id)
        ->call('replaceCurrent')
        ->set('reason', '')
        ->call('confirm')
        ->assertHasErrors(['reason']);

    expect($active->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($second->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('the Inbox defers an Emergência classification to the modal instead of writing it', function () {
    $task = Activity::factory()->task()->create();

    Livewire::test('pages::inbox')
        ->call('updateServiceClass', $task->id, 'emergency')
        ->assertDispatched('open-emergency-modal', taskId: $task->id);

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('the Task Modal offers Manter/Substituir inline when the slot is taken', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio antigo')->create([
        'title' => 'Hotfix do checkout',
    ]);
    $task = Activity::factory()->issue()->todo()->create();

    $component = Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('serviceClass', 'emergency')
        ->set('emergencyReason', 'Incêndio novo')
        ->call('saveTask')
        ->assertSet('emergencyConflict.id', $active->id)
        ->assertSee('Já existe uma Emergência ativa');

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);

    $component->call('replaceEmergency');

    expect($active->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($task->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->fresh()->emergency_reason)->toBe('Incêndio novo');
});

test('the Task Modal keeps the current Emergência and falls back to the task original class', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio antigo')->create();
    $task = Activity::factory()->issue()->todo()->create(['title' => 'Outro item']);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('serviceClass', 'emergency')
        ->set('emergencyReason', 'Incêndio novo')
        ->call('saveTask')
        ->call('keepCurrentEmergency')
        ->assertSet('emergencyConflict', null)
        ->assertSet('serviceClass', ServiceClass::Standard->value);

    expect($active->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});
