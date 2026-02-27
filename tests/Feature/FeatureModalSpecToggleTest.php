<?php

use App\Enums\TaskStatus;
use App\Models\Feature;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('Spec Toggle View/Edit', function () {
    test('renders spec in view mode by default when spec exists', function () {
        $feature = Feature::factory()->withSpec()->create();

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSet('editingSpec', false)
            ->assertSee('Especificação');
    });

    test('renders spec in edit mode when spec is empty', function () {
        $feature = Feature::factory()->create(['spec' => null]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSet('editingSpec', true);
    });

    test('new feature starts in edit mode', function () {
        Livewire::test('feature-modal')
            ->call('open')
            ->assertSet('editingSpec', true);
    });

    test('toggle alternates between view and edit modes', function () {
        $feature = Feature::factory()->withSpec()->create();

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSet('editingSpec', false)
            ->call('toggleSpec')
            ->assertSet('editingSpec', true)
            ->call('toggleSpec')
            ->assertSet('editingSpec', false);
    });

    test('view mode renders markdown content', function () {
        $feature = Feature::factory()->create([
            'spec' => '## Test Heading',
        ]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSet('editingSpec', false)
            ->assertSeeHtml('<h2>Test Heading</h2>');
    });

    test('done feature shows read-only spec without toggle button', function () {
        $feature = Feature::factory()->create([
            'spec' => '## Done Spec',
        ]);

        // Create a task in done status to make feature status = Done
        Task::factory()->create([
            'feature_id' => $feature->id,
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSet('editingSpec', false)
            ->assertSeeHtml('<h2>Done Spec</h2>');
    });

    test('auto-saves when toggling from edit to view mode', function () {
        $feature = Feature::factory()->create([
            'spec' => 'Original spec',
        ]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->set('editingSpec', true)
            ->set('spec', 'Updated spec content')
            ->call('toggleSpec')
            ->assertSet('editingSpec', false);

        expect($feature->fresh()->spec)->toBe('Updated spec content');
    });

    test('empty spec in view mode shows empty state message', function () {
        $feature = Feature::factory()->create(['spec' => null]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSet('editingSpec', true)
            ->call('toggleSpec')
            ->assertSet('editingSpec', false)
            ->assertSee('Clique no ícone de editar para adicionar uma especificação');
    });
});
