<?php

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('user can export document as pdf', function () {
    $document = Document::factory()->create([
        'title' => 'Test Document',
        'content' => '# Hello World\n\nThis is a test document.',
        'type' => 'prd',
    ]);

    Livewire::test('pages::document-view', ['slug' => $document->slug])
        ->call('exportPdf')
        ->assertFileDownloaded('test-document.pdf');
});

test('exported pdf contains document title', function () {
    $document = Document::factory()->create([
        'title' => 'My Important Document',
        'content' => '## Section 1\n\nSome content here.',
        'type' => 'spec',
    ]);

    $component = Livewire::test('pages::document-view', ['slug' => $document->slug]);

    $response = $component->call('exportPdf');

    expect($response->effects['download']['name'])->toBe('my-important-document.pdf');
});

test('exported pdf works for all document types', function (string $type) {
    $document = Document::factory()->create([
        'title' => "Document Type {$type}",
        'content' => "# {$type} Document\n\nContent for {$type}.",
        'type' => $type,
    ]);

    Livewire::test('pages::document-view', ['slug' => $document->slug])
        ->call('exportPdf')
        ->assertFileDownloaded();
})->with(['prd', 'spec', 'decision', 'note', 'reference']);

test('exported pdf includes project info when document has project', function () {
    $project = Project::factory()->create([
        'name' => 'Test Project',
    ]);

    $document = Document::factory()->create([
        'title' => 'Project Document',
        'content' => '# Project Doc\n\nBelongs to a project.',
        'project_id' => $project->id,
    ]);

    Livewire::test('pages::document-view', ['slug' => $document->slug])
        ->call('exportPdf')
        ->assertFileDownloaded('project-document.pdf');
});

test('pdf template renders correctly', function () {
    $project = Project::factory()->create(['name' => 'SoloBoard']);
    $document = Document::factory()->create([
        'title' => 'PRD v4',
        'content' => "## Overview\n\nThis is the PRD.\n\n### Features\n\n- Feature 1\n- Feature 2",
        'type' => 'prd',
        'project_id' => $project->id,
    ]);

    $view = view('pdf.document', [
        'document' => $document,
        'htmlContent' => '<h2>Overview</h2><p>This is the PRD.</p>',
        'typeColor' => '#3b82f6',
    ])->render();

    expect($view)
        ->toContain('PRD v4')
        ->toContain('SoloBoard')
        ->toContain('Overview')
        ->toContain('PRD');
});
