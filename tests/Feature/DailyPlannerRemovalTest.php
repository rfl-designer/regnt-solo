<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\User;
use App\Observers\ActivityObserver;
use App\Services\AiAssistantService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * O que deixou de existir com o ritual matinal (issue #147).
 *
 * Testes de remoção existem para impedir a volta silenciosa de uma segunda
 * fonte de verdade: o plano do dia, o carry-over, o checkbox que concluía
 * globalmente e a ação de "planejar" no palette. Cada um deles duplicava o
 * quadro em outro lugar.
 */
test('the daily planner page no longer exists', function () {
    expect(file_exists(resource_path('views/pages/⚡daily-planner.blade.php')))->toBeFalse()
        ->and(file_exists(app_path('Models/DailyPlan.php')))->toBeFalse();
});

test('the old daily route redirects to the ritual instead of rendering a planner', function () {
    $this->get('/daily')->assertRedirect('/ritual');

    expect(Route::has('daily'))->toBeFalse()
        ->and(Route::has('ritual'))->toBeTrue();
});

test('the keyboard shortcut and the palette hint point at the ritual', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain("Livewire.navigate('/ritual')")
        ->and($layout)->not->toContain("Livewire.navigate('/daily')");
});

test('the sidebar links to the ritual, not to the planner', function () {
    $this->get('/kanban')
        ->assertOk()
        ->assertSee('Ritual')
        ->assertSee(route('ritual'));
});

test('the palette no longer offers the "planejar" command', function () {
    $component = Livewire::test('command-palette')->set('search', '>');

    $labels = collect($component->instance()->results['commands'])->pluck('label')->all();

    expect($labels)->not->toContain('planejar')
        ->and($labels)->toContain('mover');
});

test('the palette refuses "planejar" as an unknown command', function () {
    $task = Activity::factory()->task()->todo()->create(['title' => 'Recado qualquer']);

    Livewire::test('command-palette')->call('executeCommand', 'planejar:'.$task->id);

    // Nada a assertar no plano — ele não existe mais; o que importa é que a
    // task não mudou e o comando não é reconhecido.
    expect($task->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('concluding an item no longer writes anything outside the board', function () {
    $activity = Activity::factory()->issue()->doing()->create();

    $activity->markAsDone();

    expect(Schema::hasTable('daily_plan_activity'))->toBeFalse()
        ->and($activity->fresh()->status)->toBe(ActivityStatus::Done);
});

test('an item due today is no longer auto-added to anything', function () {
    $activity = Activity::factory()->issue()->todo()->create(['due_date' => today()->toDateString()]);

    expect(method_exists(ActivityObserver::class, 'addToDailyPlanIfDueToday'))->toBeFalse()
        ->and($activity->fresh()->due_date->isToday())->toBeTrue();
});

test('the AI assistant no longer suggests a daily plan', function () {
    expect(method_exists(AiAssistantService::class, 'suggestDailyPlan'))->toBeFalse();
});
