<?php

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('documents page renders', function () {
    $this->get(route('documents'))->assertSuccessful();
});

test('documents page shows documents', function () {
    $doc = Document::factory()->create(['title' => 'My Test Document']);

    Livewire::test('pages::documents')
        ->assertSee('My Test Document');
});

test('documents page shows empty state when no documents', function () {
    Livewire::test('pages::documents')
        ->assertSee('Nenhum documento');
});

test('documents page filters by project', function () {
    $project = Project::factory()->create();
    Document::factory()->forProject($project)->create(['title' => 'Project Doc']);
    Document::factory()->create(['title' => 'Global Doc', 'project_id' => null]);

    Livewire::test('pages::documents', ['filterProject' => (string) $project->id])
        ->assertSee('Project Doc')
        ->assertDontSee('Global Doc');
});

test('documents page filters by type', function () {
    Document::factory()->prd()->create(['title' => 'My PRD']);
    Document::factory()->spec()->create(['title' => 'My Spec']);

    Livewire::test('pages::documents', ['filterType' => 'prd'])
        ->assertSee('My PRD')
        ->assertDontSee('My Spec');
});

test('documents page filters global documents', function () {
    $project = Project::factory()->create();
    Document::factory()->forProject($project)->create(['title' => 'Project Doc']);
    Document::factory()->create(['title' => 'Global Doc', 'project_id' => null]);

    Livewire::test('pages::documents', ['filterProject' => 'global'])
        ->assertSee('Global Doc')
        ->assertDontSee('Project Doc');
});

test('documents page can delete a document', function () {
    $doc = Document::factory()->create(['title' => 'To Delete']);

    Livewire::test('pages::documents')
        ->call('deleteDocument', $doc->id);

    expect(Document::count())->toBe(0);
});

test('documents page can toggle pin', function () {
    $doc = Document::factory()->create(['is_pinned' => false]);

    Livewire::test('pages::documents')
        ->call('togglePin', $doc->id);

    expect($doc->fresh()->is_pinned)->toBeTrue();
});

test('document view page renders', function () {
    $doc = Document::factory()->create(['title' => 'View Me', 'content' => '# Hello']);

    $this->get(route('document.view', $doc->slug))->assertSuccessful();
});

test('document view page shows document content', function () {
    $doc = Document::factory()->create([
        'title' => 'My Document',
        'content' => '# Hello World',
    ]);

    Livewire::test('pages::document-view', ['slug' => $doc->slug])
        ->assertSee('My Document')
        ->assertSee('Hello World');
});

test('document view page shows breadcrumbs with project', function () {
    $project = Project::factory()->create(['name' => 'Test Project', 'emoji' => '🚀']);
    $doc = Document::factory()->forProject($project)->create(['title' => 'My Doc']);

    Livewire::test('pages::document-view', ['slug' => $doc->slug])
        ->assertSee('Test Project')
        ->assertSee('My Doc');
});

test('document view page can toggle pin', function () {
    $doc = Document::factory()->create(['is_pinned' => false]);

    Livewire::test('pages::document-view', ['slug' => $doc->slug])
        ->call('togglePin');

    expect($doc->fresh()->is_pinned)->toBeTrue();
});

test('document view page can delete and redirect', function () {
    $doc = Document::factory()->create();

    Livewire::test('pages::document-view', ['slug' => $doc->slug])
        ->call('deleteDocument')
        ->assertRedirect(route('documents'));

    expect(Document::count())->toBe(0);
});

test('pinned documents appear first in list', function () {
    Document::factory()->create(['title' => 'Normal Doc', 'is_pinned' => false, 'sort_order' => 0]);
    Document::factory()->create(['title' => 'Pinned Doc', 'is_pinned' => true, 'sort_order' => 0]);

    Livewire::test('pages::documents')
        ->assertSeeInOrder(['Pinned Doc', 'Normal Doc']);
});

test('documents page requires authentication', function () {
    auth()->logout();

    $this->get(route('documents'))->assertRedirect(route('login'));
});

test('sidebar has docs link', function () {
    $this->get(route('dashboard'))
        ->assertSee('Docs');
});
