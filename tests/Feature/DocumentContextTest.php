<?php

use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateDocumentTool;
use App\Mcp\Tools\GetDocumentTool;
use App\Mcp\Tools\ListDocumentsTool;
use App\Mcp\Tools\UpdateDocumentTool;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

// ========================================
// Migration / Model tests
// ========================================

test('is_context field exists and defaults to false', function () {
    $document = Document::factory()->create();
    $document->refresh();

    expect($document->is_context)->toBeFalse();
});

test('document can be created with is_context true', function () {
    $document = Document::factory()->context()->create();

    expect($document->is_context)->toBeTrue();

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'is_context' => true,
    ]);
});

test('scope context filters correctly', function () {
    Document::factory()->create(['is_context' => false]);
    Document::factory()->context()->create(['title' => 'Context Doc']);

    $contextDocs = Document::context()->get();

    expect($contextDocs)->toHaveCount(1)
        ->and($contextDocs->first()->title)->toBe('Context Doc');
});

test('is_context is cast to boolean', function () {
    $document = Document::factory()->context()->create();

    expect($document->is_context)->toBeBool()
        ->and($document->is_context)->toBeTrue();
});

// ========================================
// MCP Tools tests
// ========================================

test('list-documents returns only context documents by default', function () {
    Document::factory()->create(['title' => 'Regular Doc', 'is_context' => false]);
    Document::factory()->context()->create(['title' => 'Context Doc']);

    $response = SoloBoardServer::tool(ListDocumentsTool::class, []);

    $response->assertOk();
    $response->assertSee('Context Doc');
    $response->assertDontSee('Regular Doc');
});

test('list-documents with include_all returns all documents', function () {
    Document::factory()->create(['title' => 'Regular Doc', 'is_context' => false]);
    Document::factory()->context()->create(['title' => 'Context Doc']);

    $response = SoloBoardServer::tool(ListDocumentsTool::class, [
        'include_all' => true,
    ]);

    $response->assertOk();
    $response->assertSee('Regular Doc');
    $response->assertSee('Context Doc');
});

test('list-documents returns truncated content for long documents', function () {
    $longContent = Str::repeat('A', 300).'MIDDLE'.Str::repeat('Z', 300);
    Document::factory()->context()->create(['content' => $longContent]);

    $response = SoloBoardServer::tool(ListDocumentsTool::class, []);

    $response->assertOk();
    $response->assertSee('[...]');
    $response->assertDontSee('MIDDLE');
});

test('list-documents returns full content for short documents', function () {
    $shortContent = 'Short content under 510 characters';
    Document::factory()->context()->create(['content' => $shortContent]);

    $response = SoloBoardServer::tool(ListDocumentsTool::class, []);

    $response->assertOk();
    $response->assertSee($shortContent);
    $response->assertDontSee('[...]');
});

test('list-documents returns is_context field', function () {
    Document::factory()->context()->create();

    $response = SoloBoardServer::tool(ListDocumentsTool::class, []);

    $response->assertOk();
    $response->assertSee('"is_context": true');
});

test('get-document returns is_context in response', function () {
    $document = Document::factory()->context()->create();

    $response = SoloBoardServer::tool(GetDocumentTool::class, [
        'document_id' => $document->id,
    ]);

    $response->assertOk();
    $response->assertSee('"is_context": true');
});

test('get-document returns is_context false for non-context documents', function () {
    $document = Document::factory()->create(['is_context' => false]);

    $response = SoloBoardServer::tool(GetDocumentTool::class, [
        'document_id' => $document->id,
    ]);

    $response->assertOk();
    $response->assertSee('"is_context": false');
});

test('create-document accepts is_context parameter', function () {
    $response = SoloBoardServer::tool(CreateDocumentTool::class, [
        'title' => 'Context Document',
        'content' => '# AI Context',
        'is_context' => true,
    ]);

    $response->assertOk();
    $response->assertSee('Document created successfully');

    $this->assertDatabaseHas('documents', [
        'title' => 'Context Document',
        'is_context' => true,
    ]);
});

test('create-document defaults is_context to false', function () {
    $response = SoloBoardServer::tool(CreateDocumentTool::class, [
        'title' => 'Regular Document',
        'content' => '# Content',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('documents', [
        'title' => 'Regular Document',
        'is_context' => false,
    ]);
});

test('update-document accepts is_context parameter', function () {
    $document = Document::factory()->create(['is_context' => false]);

    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'document_id' => $document->id,
        'is_context' => true,
    ]);

    $response->assertOk();
    $response->assertSee('Document updated successfully');

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'is_context' => true,
    ]);
});

test('update-document can disable is_context', function () {
    $document = Document::factory()->context()->create();

    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'document_id' => $document->id,
        'is_context' => false,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'is_context' => false,
    ]);
});

// ========================================
// UI tests
// ========================================

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('document edit page shows context checkbox', function () {
    Livewire::test('pages::document-edit')
        ->assertSee('Documento de Contexto');
});

test('document edit page loads is_context from existing document', function () {
    $doc = Document::factory()->context()->create();

    Livewire::test('pages::document-edit', ['slug' => $doc->slug])
        ->assertSet('isContext', true);
});

test('can save document with is_context enabled', function () {
    Livewire::test('pages::document-edit')
        ->set('title', 'AI Context Doc')
        ->set('content', 'Content for AI')
        ->set('isContext', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Document::first()->is_context)->toBeTrue();
});

test('document view page shows context badge when is_context is true', function () {
    $doc = Document::factory()->context()->create();

    Livewire::test('pages::document-view', ['slug' => $doc->slug])
        ->assertSee('Contexto');
});

test('document view page does not show context badge when is_context is false', function () {
    $doc = Document::factory()->create(['is_context' => false]);

    Livewire::test('pages::document-view', ['slug' => $doc->slug])
        ->assertDontSee('Contexto');
});

test('document view page shows document ID', function () {
    $doc = Document::factory()->create();

    Livewire::test('pages::document-view', ['slug' => $doc->slug])
        ->assertSee("#D-{$doc->id}");
});

test('document edit page shows document ID when editing', function () {
    $doc = Document::factory()->create();

    Livewire::test('pages::document-edit', ['slug' => $doc->slug])
        ->assertSee("#D-{$doc->id}");
});
