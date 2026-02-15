<?php

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('document create page renders', function () {
    $this->get(route('document.create'))->assertStatus(200);
});

test('document edit page renders', function () {
    $doc = Document::factory()->create();

    $this->get(route('document.edit', $doc->slug))->assertStatus(200);
});

test('can create a new document', function () {
    Livewire::test('pages::document-edit')
        ->set('title', 'My New Document')
        ->set('content', '# Hello World')
        ->set('type', 'prd')
        ->call('save')
        ->assertHasNoErrors();

    expect(Document::count())->toBe(1);

    $doc = Document::first();
    expect($doc->title)->toBe('My New Document')
        ->and($doc->content)->toBe('# Hello World')
        ->and($doc->type)->toBe(DocumentType::Prd)
        ->and($doc->slug)->toBe('my-new-document');
});

test('can create a document with project', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::document-edit')
        ->set('title', 'Project Doc')
        ->set('content', 'Content here')
        ->set('type', 'spec')
        ->set('projectId', (string) $project->id)
        ->call('save')
        ->assertHasNoErrors();

    $doc = Document::first();
    expect($doc->project_id)->toBe($project->id);
});

test('can edit an existing document', function () {
    $doc = Document::factory()->create([
        'title' => 'Original Title',
        'content' => 'Original content',
    ]);

    Livewire::test('pages::document-edit', ['slug' => $doc->slug])
        ->set('title', 'Updated Title')
        ->set('content', 'Updated content')
        ->call('save')
        ->assertHasNoErrors();

    $doc->refresh();
    expect($doc->title)->toBe('Updated Title')
        ->and($doc->content)->toBe('Updated content');
});

test('validates required title', function () {
    Livewire::test('pages::document-edit')
        ->set('title', '')
        ->set('content', 'Some content')
        ->call('save')
        ->assertHasErrors(['title']);
});

test('validates required content', function () {
    Livewire::test('pages::document-edit')
        ->set('title', 'My Doc')
        ->set('content', '')
        ->call('save')
        ->assertHasErrors(['content']);
});

test('can toggle preview mode', function () {
    Livewire::test('pages::document-edit')
        ->assertSet('showPreview', false)
        ->call('togglePreview')
        ->assertSet('showPreview', true)
        ->call('togglePreview')
        ->assertSet('showPreview', false);
});

test('edit page loads existing document data', function () {
    $project = Project::factory()->create();
    $doc = Document::factory()->forProject($project)->prd()->pinned()->create([
        'title' => 'My PRD',
        'content' => '# PRD Content',
    ]);

    Livewire::test('pages::document-edit', ['slug' => $doc->slug])
        ->assertSet('title', 'My PRD')
        ->assertSet('content', '# PRD Content')
        ->assertSet('type', 'prd')
        ->assertSet('projectId', (string) $project->id)
        ->assertSet('isPinned', true);
});

test('can create pinned document', function () {
    Livewire::test('pages::document-edit')
        ->set('title', 'Pinned Doc')
        ->set('content', 'Content')
        ->set('isPinned', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Document::first()->is_pinned)->toBeTrue();
});

test('document create page requires authentication', function () {
    auth()->logout();

    $this->get(route('document.create'))->assertRedirect(route('login'));
});

test('document edit page requires authentication', function () {
    auth()->logout();
    $doc = Document::factory()->create();

    $this->get(route('document.edit', $doc->slug))->assertRedirect(route('login'));
});

test('generated slug updates with title', function () {
    Livewire::test('pages::document-edit')
        ->set('title', 'My New Document')
        ->assertSee('my-new-document');
});
