<?php

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
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

test('document view page can delete and redirect to project', function () {
    $project = Project::factory()->create();
    $doc = Document::factory()->forProject($project)->create();

    Livewire::test('pages::document-view', ['slug' => $doc->slug])
        ->call('deleteDocument')
        ->assertRedirect(route('project.detail', $project->slug).'?tab=docs');

    expect(Document::count())->toBe(0);
});

test('document view page can delete global document and redirect to projects', function () {
    $doc = Document::factory()->create(['project_id' => null]);

    Livewire::test('pages::document-view', ['slug' => $doc->slug])
        ->call('deleteDocument')
        ->assertRedirect(route('projects'));

    expect(Document::count())->toBe(0);
});

test('document view requires authentication', function () {
    auth()->logout();
    $doc = Document::factory()->create();

    $this->get(route('document.view', $doc->slug))->assertRedirect(route('login'));
});
