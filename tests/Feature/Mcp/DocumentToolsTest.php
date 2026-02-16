<?php

use App\Enums\DocumentType;
use App\Enums\TaskStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateDocumentTool;
use App\Mcp\Tools\DeleteDocumentTool;
use App\Mcp\Tools\GetDocumentTool;
use App\Mcp\Tools\GetProjectContextTool;
use App\Mcp\Tools\ListDocumentsTool;
use App\Mcp\Tools\UpdateDocumentTool;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;

// ListDocumentsTool tests
test('list-documents returns all documents', function () {
    Document::factory()->count(3)->create();

    $response = SoloBoardServer::tool(ListDocumentsTool::class, []);

    $response->assertOk();
});

test('list-documents filters by project slug', function () {
    $project = Project::factory()->create(['slug' => 'my-project']);
    Document::factory()->create(['project_id' => $project->id, 'title' => 'Project Doc']);
    Document::factory()->create(['project_id' => null, 'title' => 'Global Doc']);

    $response = SoloBoardServer::tool(ListDocumentsTool::class, [
        'project_slug' => 'my-project',
    ]);

    $response->assertOk();
    $response->assertSee('Project Doc');
    $response->assertDontSee('Global Doc');
});

test('list-documents filters by type', function () {
    Document::factory()->create(['type' => DocumentType::Prd, 'title' => 'PRD Doc']);
    Document::factory()->create(['type' => DocumentType::Note, 'title' => 'Note Doc']);

    $response = SoloBoardServer::tool(ListDocumentsTool::class, [
        'type' => 'prd',
    ]);

    $response->assertOk();
    $response->assertSee('PRD Doc');
    $response->assertDontSee('Note Doc');
});

test('list-documents respects limit', function () {
    Document::factory()->count(5)->create();

    $response = SoloBoardServer::tool(ListDocumentsTool::class, [
        'limit' => 2,
    ]);

    $response->assertOk();
});

test('list-documents shows pinned first', function () {
    Document::factory()->create(['is_pinned' => false, 'title' => 'Regular Doc']);
    Document::factory()->create(['is_pinned' => true, 'title' => 'Pinned Doc']);

    $response = SoloBoardServer::tool(ListDocumentsTool::class, []);

    $response->assertOk();
    $response->assertSee('"is_pinned": true');
});

test('list-documents fails with invalid project slug', function () {
    $response = SoloBoardServer::tool(ListDocumentsTool::class, [
        'project_slug' => 'non-existent',
    ]);

    $response->assertHasErrors();
});

test('list-documents fails with invalid type', function () {
    $response = SoloBoardServer::tool(ListDocumentsTool::class, [
        'type' => 'invalid',
    ]);

    $response->assertHasErrors();
});

// GetDocumentTool tests
test('get-document returns document by slug', function () {
    $document = Document::factory()->create(['slug' => 'test-doc', 'content' => '# Test Content']);

    $response = SoloBoardServer::tool(GetDocumentTool::class, [
        'slug' => 'test-doc',
    ]);

    $response->assertOk();
    $response->assertSee($document->title);
    $response->assertSee('# Test Content');
});

test('get-document returns document by id', function () {
    $document = Document::factory()->create(['content' => '# Content by ID']);

    $response = SoloBoardServer::tool(GetDocumentTool::class, [
        'document_id' => $document->id,
    ]);

    $response->assertOk();
    $response->assertSee($document->title);
    $response->assertSee('# Content by ID');
});

test('get-document includes project info', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $document = Document::factory()->create(['project_id' => $project->id]);

    $response = SoloBoardServer::tool(GetDocumentTool::class, [
        'document_id' => $document->id,
    ]);

    $response->assertOk();
    $response->assertSee('Test Project');
});

test('get-document fails without slug or id', function () {
    $response = SoloBoardServer::tool(GetDocumentTool::class, []);

    $response->assertHasErrors();
});

test('get-document fails for non-existent document', function () {
    $response = SoloBoardServer::tool(GetDocumentTool::class, [
        'slug' => 'non-existent',
    ]);

    $response->assertHasErrors();
});

// CreateDocumentTool tests
test('create-document creates document with defaults', function () {
    $response = SoloBoardServer::tool(CreateDocumentTool::class, [
        'title' => 'New Document',
        'content' => '# New Document Content',
    ]);

    $response->assertOk();
    $response->assertSee('New Document');
    $response->assertSee('"type": "note"');
    $response->assertSee('Document created successfully');

    $this->assertDatabaseHas('documents', [
        'title' => 'New Document',
        'type' => 'note',
    ]);
});

test('create-document creates document with project', function () {
    $project = Project::factory()->create(['slug' => 'test-project']);

    $response = SoloBoardServer::tool(CreateDocumentTool::class, [
        'title' => 'Project Document',
        'content' => '# Project Content',
        'project_slug' => 'test-project',
        'type' => 'prd',
        'is_pinned' => true,
    ]);

    $response->assertOk();
    $response->assertSee('Project Document');
    $response->assertSee('"type": "prd"');

    $this->assertDatabaseHas('documents', [
        'title' => 'Project Document',
        'project_id' => $project->id,
        'type' => 'prd',
        'is_pinned' => true,
    ]);
});

test('create-document generates slug automatically', function () {
    $response = SoloBoardServer::tool(CreateDocumentTool::class, [
        'title' => 'Document With Slug',
        'content' => '# Content',
    ]);

    $response->assertOk();
    $response->assertSee('"slug": "document-with-slug"');
});

test('create-document fails without title', function () {
    $response = SoloBoardServer::tool(CreateDocumentTool::class, [
        'content' => '# Content',
    ]);

    $response->assertHasErrors();
});

test('create-document fails without content', function () {
    $response = SoloBoardServer::tool(CreateDocumentTool::class, [
        'title' => 'Title Only',
    ]);

    $response->assertHasErrors();
});

test('create-document fails with invalid project slug', function () {
    $response = SoloBoardServer::tool(CreateDocumentTool::class, [
        'title' => 'Test',
        'content' => '# Content',
        'project_slug' => 'non-existent',
    ]);

    $response->assertHasErrors();
});

// UpdateDocumentTool tests
test('update-document updates document by slug', function () {
    $document = Document::factory()->create(['slug' => 'old-doc', 'title' => 'Old Title']);

    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'slug' => 'old-doc',
        'title' => 'New Title',
    ]);

    $response->assertOk();
    $response->assertSee('New Title');
    $response->assertSee('Document updated successfully');

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'title' => 'New Title',
    ]);
});

test('update-document updates document by id', function () {
    $document = Document::factory()->create(['title' => 'Old Title']);

    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'document_id' => $document->id,
        'title' => 'New Title',
    ]);

    $response->assertOk();
    $response->assertSee('New Title');
});

test('update-document updates content', function () {
    $document = Document::factory()->create(['content' => '# Old']);

    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'document_id' => $document->id,
        'content' => '# New Content',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'content' => '# New Content',
    ]);
});

test('update-document changes project', function () {
    $oldProject = Project::factory()->create(['slug' => 'old-project']);
    $newProject = Project::factory()->create(['slug' => 'new-project']);
    $document = Document::factory()->create(['project_id' => $oldProject->id]);

    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'document_id' => $document->id,
        'project_slug' => 'new-project',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'project_id' => $newProject->id,
    ]);
});

test('update-document removes from project', function () {
    $project = Project::factory()->create();
    $document = Document::factory()->create(['project_id' => $project->id]);

    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'document_id' => $document->id,
        'project_slug' => '',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'project_id' => null,
    ]);
});

test('update-document updates type', function () {
    $document = Document::factory()->create(['type' => DocumentType::Note]);

    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'document_id' => $document->id,
        'type' => 'spec',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'type' => 'spec',
    ]);
});

test('update-document fails without slug or id', function () {
    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'title' => 'New Title',
    ]);

    $response->assertHasErrors();
});

test('update-document fails without changes', function () {
    $document = Document::factory()->create();

    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'document_id' => $document->id,
    ]);

    $response->assertHasErrors();
});

test('update-document fails for non-existent document', function () {
    $response = SoloBoardServer::tool(UpdateDocumentTool::class, [
        'slug' => 'non-existent',
        'title' => 'New Title',
    ]);

    $response->assertHasErrors();
});

// DeleteDocumentTool tests
test('delete-document deletes document by slug', function () {
    $document = Document::factory()->create(['slug' => 'to-delete']);

    $response = SoloBoardServer::tool(DeleteDocumentTool::class, [
        'slug' => 'to-delete',
    ]);

    $response->assertOk();
    $response->assertSee('deleted successfully');

    $this->assertDatabaseMissing('documents', ['id' => $document->id]);
});

test('delete-document deletes document by id', function () {
    $document = Document::factory()->create();

    $response = SoloBoardServer::tool(DeleteDocumentTool::class, [
        'document_id' => $document->id,
    ]);

    $response->assertOk();
    $response->assertSee('deleted successfully');

    $this->assertDatabaseMissing('documents', ['id' => $document->id]);
});

test('delete-document fails without slug or id', function () {
    $response = SoloBoardServer::tool(DeleteDocumentTool::class, []);

    $response->assertHasErrors();
});

test('delete-document fails for non-existent document', function () {
    $response = SoloBoardServer::tool(DeleteDocumentTool::class, [
        'slug' => 'non-existent',
    ]);

    $response->assertHasErrors();
});

// GetProjectContextTool tests
test('get-project-context returns full context', function () {
    $project = Project::factory()->create(['slug' => 'test-project', 'name' => 'Test Project']);
    Document::factory()->create(['project_id' => $project->id, 'title' => 'Project PRD']);
    Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Todo, 'title' => 'Active Task']);
    Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Done, 'title' => 'Done Task']);

    $response = SoloBoardServer::tool(GetProjectContextTool::class, [
        'project_slug' => 'test-project',
    ]);

    $response->assertOk();
    $response->assertSee('Test Project');
    $response->assertSee('Project PRD');
    $response->assertSee('Active Task');
    $response->assertDontSee('Done Task'); // Active tasks only
    $response->assertSee('"total_tasks": 2');
});

test('get-project-context returns documents with content', function () {
    $project = Project::factory()->create(['slug' => 'doc-project']);
    Document::factory()->create([
        'project_id' => $project->id,
        'title' => 'Full Doc',
        'content' => '# Complete markdown content',
    ]);

    $response = SoloBoardServer::tool(GetProjectContextTool::class, [
        'project_slug' => 'doc-project',
    ]);

    $response->assertOk();
    $response->assertSee('# Complete markdown content');
});

test('get-project-context returns metrics', function () {
    $project = Project::factory()->create(['slug' => 'metrics-project']);
    Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Inbox]);
    Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Todo]);
    Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Done]);

    $response = SoloBoardServer::tool(GetProjectContextTool::class, [
        'project_slug' => 'metrics-project',
    ]);

    $response->assertOk();
    $response->assertSee('tasks_by_status');
    $response->assertSee('"inbox": 1');
    $response->assertSee('"todo": 1');
    $response->assertSee('"done": 1');
});

test('get-project-context returns active tasks with session prompt', function () {
    $project = Project::factory()->create(['slug' => 'session-project']);
    Task::factory()->session()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Doing,
        'title' => 'Session Task',
    ]);

    $response = SoloBoardServer::tool(GetProjectContextTool::class, [
        'project_slug' => 'session-project',
    ]);

    $response->assertOk();
    $response->assertSee('Session Task');
    $response->assertSee('session_prompt');
});

test('get-project-context fails without project slug', function () {
    $response = SoloBoardServer::tool(GetProjectContextTool::class, []);

    $response->assertHasErrors();
});

test('get-project-context fails for non-existent project', function () {
    $response = SoloBoardServer::tool(GetProjectContextTool::class, [
        'project_slug' => 'non-existent',
    ]);

    $response->assertHasErrors();
});
