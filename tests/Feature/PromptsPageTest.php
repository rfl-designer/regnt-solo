<?php

use App\Models\Prompt;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

describe('Prompts Page Access', function (): void {
    test('prompts route requires authentication', function (): void {
        auth()->logout();

        $this->get(route('prompts'))
            ->assertRedirect(route('login'));
    });

    test('prompts page renders correctly for authenticated users', function (): void {
        $this->get(route('prompts'))
            ->assertOk();
    });

    test('prompts component renders successfully', function (): void {
        Livewire::test('pages::prompts')
            ->assertSuccessful()
            ->assertSee('Prompts');
    });
});

describe('Prompts Listing', function (): void {
    test('shows empty state when no prompts exist', function (): void {
        Livewire::test('pages::prompts')
            ->assertSee('Nenhum prompt');
    });

    test('lists existing prompts', function (): void {
        Prompt::factory()->create(['name' => 'Feature Prompt']);
        Prompt::factory()->create(['name' => 'Bugfix Prompt']);

        Livewire::test('pages::prompts')
            ->assertSee('Feature Prompt')
            ->assertSee('Bugfix Prompt');
    });

    test('orders prompts by updated_at desc', function (): void {
        $older = Prompt::factory()->create(['name' => 'Older Prompt']);
        $newer = Prompt::factory()->create(['name' => 'Newer Prompt']);

        $older->updated_at = now()->subDay();
        $older->save();
        $newer->updated_at = now();
        $newer->save();

        $html = Livewire::test('pages::prompts')->html();

        expect(strpos($html, 'Newer Prompt'))->toBeLessThan(strpos($html, 'Older Prompt'));
    });

    test('search filters prompts by name', function (): void {
        Prompt::factory()->create(['name' => 'Deploy Checklist']);
        Prompt::factory()->create(['name' => 'Code Review']);

        Livewire::test('pages::prompts')
            ->set('search', 'Deploy')
            ->assertSee('Deploy Checklist')
            ->assertDontSee('Code Review');
    });
});

describe('Prompt CRUD', function (): void {
    test('can create a new prompt', function (): void {
        Livewire::test('pages::prompts')
            ->call('openForm')
            ->set('name', 'New Prompt')
            ->set('content', '## User Story')
            ->call('save')
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('prompts', [
            'name' => 'New Prompt',
            'content' => '## User Story',
        ]);
    });

    test('prompt name is required', function (): void {
        Livewire::test('pages::prompts')
            ->set('name', '')
            ->set('content', 'some content')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    });

    test('prompt name cannot exceed 255 characters', function (): void {
        Livewire::test('pages::prompts')
            ->set('name', str_repeat('a', 256))
            ->set('content', 'some content')
            ->call('save')
            ->assertHasErrors(['name' => 'max']);
    });

    test('prompt content is required', function (): void {
        Livewire::test('pages::prompts')
            ->set('name', 'Has Name')
            ->set('content', '')
            ->call('save')
            ->assertHasErrors(['content' => 'required']);
    });

    test('editing reopens form pre-filled and persists changes', function (): void {
        $prompt = Prompt::factory()->create([
            'name' => 'Old Name',
            'content' => 'Old content',
        ]);

        Livewire::test('pages::prompts')
            ->call('openForm', $prompt->id)
            ->assertSet('editingId', $prompt->id)
            ->assertSet('name', 'Old Name')
            ->assertSet('content', 'Old content')
            ->set('name', 'New Name')
            ->call('save');

        $this->assertDatabaseHas('prompts', [
            'id' => $prompt->id,
            'name' => 'New Name',
        ]);
    });

    test('delete requires confirmation modal before removing', function (): void {
        $prompt = Prompt::factory()->create();

        Livewire::test('pages::prompts')
            ->call('confirmDelete', $prompt->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deletingId', $prompt->id)
            ->call('delete')
            ->assertSet('showDeleteModal', false);

        $this->assertDatabaseMissing('prompts', ['id' => $prompt->id]);
    });
});

describe('Prompt Copy', function (): void {
    test('card embeds prompt content in the click-to-copy handler', function (): void {
        Prompt::factory()->create([
            'name' => 'Copyable',
            'content' => 'markdown source to copy',
        ]);

        Livewire::test('pages::prompts')
            ->assertSeeHtml('navigator.clipboard.writeText')
            ->assertSeeHtml('markdown source to copy');
    });
});
