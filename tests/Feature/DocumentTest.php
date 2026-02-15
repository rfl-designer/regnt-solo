<?php

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Project;

test('can create a document', function () {
    $document = Document::factory()->create([
        'title' => 'Meu Documento',
        'content' => '# Hello World',
    ]);

    expect($document)
        ->title->toBe('Meu Documento')
        ->content->toBe('# Hello World')
        ->type->toBe(DocumentType::Note)
        ->is_pinned->toBeFalse();
});

test('document auto-generates slug from title', function () {
    $document = Document::factory()->create([
        'title' => 'PRD - API Gateway v2',
    ]);

    expect($document->slug)->toBe('prd-api-gateway-v2');
});

test('document slug is unique within same project', function () {
    $project = Project::factory()->create();

    $doc1 = Document::factory()->forProject($project)->create(['title' => 'My Doc']);
    $doc2 = Document::factory()->forProject($project)->create(['title' => 'My Doc']);

    expect($doc1->slug)->toBe('my-doc')
        ->and($doc2->slug)->toBe('my-doc-1');
});

test('document slug is globally unique across different projects', function () {
    $project1 = Project::factory()->create();
    $project2 = Project::factory()->create();

    $doc1 = Document::factory()->forProject($project1)->create(['title' => 'My Doc']);
    $doc2 = Document::factory()->forProject($project2)->create(['title' => 'My Doc']);

    expect($doc1->slug)->toBe('my-doc')
        ->and($doc2->slug)->toBe('my-doc-1');
});

test('document casts type to enum', function () {
    $document = Document::factory()->prd()->create();

    expect($document->type)->toBeInstanceOf(DocumentType::class)
        ->and($document->type)->toBe(DocumentType::Prd);
});

test('document belongs to project', function () {
    $project = Project::factory()->create();
    $document = Document::factory()->forProject($project)->create();

    expect($document->project->id)->toBe($project->id);
});

test('project has many documents', function () {
    $project = Project::factory()->create();
    Document::factory()->count(3)->forProject($project)->create();

    expect($project->documents)->toHaveCount(3);
});

test('cascade delete removes documents when project is deleted', function () {
    $project = Project::factory()->create();
    Document::factory()->count(2)->forProject($project)->create();

    expect(Document::count())->toBe(2);

    $project->delete();

    expect(Document::count())->toBe(0);
});

test('document can be global (no project)', function () {
    $document = Document::factory()->create(['project_id' => null]);

    expect($document->project_id)->toBeNull()
        ->and($document->project)->toBeNull();
});

test('forProject scope filters by project', function () {
    $project = Project::factory()->create();
    Document::factory()->forProject($project)->create();
    Document::factory()->create(['project_id' => null]);

    expect(Document::query()->forProject($project->id)->count())->toBe(1);
});

test('global scope filters documents without project', function () {
    $project = Project::factory()->create();
    Document::factory()->forProject($project)->create();
    Document::factory()->create(['project_id' => null]);

    expect(Document::query()->global()->count())->toBe(1);
});

test('pinned scope filters pinned documents', function () {
    Document::factory()->pinned()->create();
    Document::factory()->create();

    expect(Document::query()->pinned()->count())->toBe(1);
});

test('byType scope filters by document type', function () {
    Document::factory()->prd()->create();
    Document::factory()->spec()->create();
    Document::factory()->create(); // note (default)

    expect(Document::query()->byType(DocumentType::Prd)->count())->toBe(1)
        ->and(Document::query()->byType(DocumentType::Note)->count())->toBe(1);
});

test('ordered scope sorts pinned first then by sort_order and title', function () {
    Document::factory()->create(['title' => 'Zebra', 'is_pinned' => false, 'sort_order' => 1]);
    Document::factory()->create(['title' => 'Alpha', 'is_pinned' => true, 'sort_order' => 0]);
    Document::factory()->create(['title' => 'Beta', 'is_pinned' => false, 'sort_order' => 0]);

    $ordered = Document::query()->ordered()->pluck('title')->all();

    expect($ordered)->toBe(['Alpha', 'Beta', 'Zebra']);
});

test('excerpt returns plain text truncated', function () {
    $document = Document::factory()->create([
        'content' => "# Hello World\n\nThis is a **bold** paragraph with some content that should be truncated.",
    ]);

    $excerpt = $document->excerpt(20);

    expect($excerpt)->not->toContain('<')
        ->and(strlen($excerpt))->toBeLessThanOrEqual(23); // 20 + "..."
});

test('document type enum has correct labels', function () {
    expect(DocumentType::Prd->label())->toBe('PRD')
        ->and(DocumentType::Spec->label())->toBe('Especificação')
        ->and(DocumentType::Decision->label())->toBe('Decisão')
        ->and(DocumentType::Note->label())->toBe('Nota')
        ->and(DocumentType::Reference->label())->toBe('Referência');
});

test('document type enum has icons', function () {
    foreach (DocumentType::cases() as $type) {
        expect($type->icon())->toBeString()->not->toBeEmpty();
    }
});

test('document type enum has colors', function () {
    foreach (DocumentType::cases() as $type) {
        expect($type->color())->toBeString()->not->toBeEmpty();
    }
});

test('slug updates when title changes', function () {
    $document = Document::factory()->create(['title' => 'Original Title']);
    expect($document->slug)->toBe('original-title');

    $document->update(['title' => 'Updated Title']);
    expect($document->fresh()->slug)->toBe('updated-title');
});
