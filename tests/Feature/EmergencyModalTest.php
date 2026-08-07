<?php

use App\Enums\ActivityStatus;
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

test('a conflict that was concluded while the modal sat open is not overwritten', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create();
    $second = Activity::factory()->issue()->todo()->create();

    $component = Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $second->id)
        ->assertSet('step', 'conflict');

    // Meanwhile, the conflicting Emergência is concluded elsewhere. It keeps
    // its classification as history — confirming here must not reach back
    // and erase it.
    $active->update(['status' => ActivityStatus::Done]);

    $component->call('replaceCurrent')
        ->set('reason', 'Incêndio novo')
        ->call('confirm');

    expect($active->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($active->fresh()->emergency_reason)->toBe('Incêndio atual')
        ->and($second->fresh()->service_class)->toBe(ServiceClass::Emergency);
});

test('a slot taken by somebody else while the modal sat open re-asks instead of overwriting', function () {
    $original = Activity::factory()->issue()->doing()->emergency('Incêndio original')->create();
    $second = Activity::factory()->issue()->todo()->create();

    $component = Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $second->id)
        ->assertSet('conflict.id', $original->id);

    // The original is demoted and a different item becomes the Emergência.
    $original->update(['service_class' => ServiceClass::Standard]);
    $newcomer = Activity::factory()->issue()->doing()->emergency('Incêndio mais novo')->create();

    $component->call('replaceCurrent')
        ->set('reason', 'Incêndio novo')
        ->call('confirm')
        ->assertSet('step', 'conflict')
        ->assertSet('conflict.id', $newcomer->id);

    expect($newcomer->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($second->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('dragging a concluded Emergência back onto the board opens Manter/Substituir instead of a dead-end toast', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create([
        'title' => 'Hotfix do checkout',
    ]);
    $done = Activity::factory()->issue()->done()->emergency('Incêndio antigo')->create();

    Livewire::test('pages::kanban')
        ->call('handleSort', $done->id, 0, 'todo')
        ->assertDispatched('open-emergency-modal', taskId: $done->id, status: 'todo');

    expect($done->fresh()->status)->toBe(ActivityStatus::Done);

    // Substituir applies the pending *move* directly: the item already
    // carries its motivo, so there is nothing left to ask.
    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $done->id, status: 'todo')
        ->assertSet('step', 'conflict')
        ->assertSet('conflict.id', $active->id)
        ->call('replaceCurrent')
        ->assertSet('showModal', false);

    expect($active->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($done->fresh()->status)->toBe(ActivityStatus::Todo)
        ->and($done->fresh()->service_class)->toBe(ServiceClass::Emergency);
});

test('keeping the current Emergência leaves the dragged card where it was', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create();
    $done = Activity::factory()->issue()->done()->emergency('Incêndio antigo')->create();

    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $done->id, status: 'todo')
        ->call('keepCurrent');

    expect($active->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($done->fresh()->status)->toBe(ActivityStatus::Done);
});

test('a pending move whose conflict disappeared just happens', function () {
    $done = Activity::factory()->issue()->done()->emergency('Incêndio antigo')->create();

    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $done->id, status: 'todo')
        ->assertSet('showModal', false);

    expect($done->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('the Inbox routes an AI-suggested Emergência through the modal with the AI motivo prefilled', function () {
    $task = Activity::factory()->task()->create(['title' => 'Task sugerida']);

    Livewire::test('emergency-modal')
        ->dispatch('open-emergency-modal', taskId: $task->id, reason: 'Vencendo em 2 dias')
        ->assertSet('reason', 'Vencendo em 2 dias')
        ->call('confirm');

    expect($task->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->fresh()->emergency_reason)->toBe('Vencendo em 2 dias');
});

test('a Task Modal replace that gets refused rolls the demotion back with it', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio antigo')->create();
    $task = Activity::factory()->issue()->todo()->create();

    $component = Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('serviceClass', 'emergency')
        ->set('emergencyReason', 'Incêndio novo')
        ->call('saveTask')
        ->assertSet('emergencyConflict.id', $active->id);

    // Make the second write fail: Data fixa with no prazo is refused by
    // another guard, so the promotion inside the swap throws.
    $component->set('serviceClass', ServiceClass::FixedDate->value)
        ->set('dueDate', null)
        ->call('replaceEmergency');

    // Neither write survived: the Emergência that held the slot still does.
    expect($active->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('the Task Modal saves done + Emergência in one go, judged by the final state', function () {
    Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create();
    $task = Activity::factory()->issue()->doing()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('serviceClass', 'emergency')
        ->set('emergencyReason', 'Virou incêndio e foi resolvido')
        ->set('status', ActivityStatus::Done->value)
        ->call('saveTask')
        ->assertSet('emergencyConflict', null);

    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Done)
        ->and($task->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->completed_at)->not->toBeNull();
});

test('keeping the current Emergência does not loop forever when the task is itself a reactivated Emergência', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create();
    $done = Activity::factory()->issue()->done()->emergency('Incêndio antigo')->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $done->id)
        ->set('status', ActivityStatus::Todo->value)
        ->call('saveTask')
        ->assertSet('emergencyConflict.id', $active->id)
        ->call('keepCurrentEmergency')
        ->assertSet('emergencyConflict', null)
        ->assertSet('serviceClass', ServiceClass::Standard->value);

    // Giving the slot up means becoming Padrão — restoring the stored
    // `emergency` class would just hit the same refusal on every retry.
    expect($done->fresh()->status)->toBe(ActivityStatus::Todo)
        ->and($done->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($active->fresh()->service_class)->toBe(ServiceClass::Emergency);
});
