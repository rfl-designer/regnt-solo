<?php

namespace App\Mcp\Tools;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateDocumentTool extends Tool
{
    protected string $name = 'update-document';

    protected string $description = 'Updates an existing document. Provide only the fields you want to change.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'slug' => 'nullable|string',
            'document_id' => 'nullable|integer',
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'project_slug' => 'nullable|string',
            'type' => ['nullable', 'string', Rule::enum(DocumentType::class)],
            'is_pinned' => 'nullable|boolean',
        ], [
            'type.Illuminate\Validation\Rules\Enum' => 'Invalid type. Valid values: prd, spec, decision, note, reference.',
        ]);

        if (empty($validated['slug']) && empty($validated['document_id'])) {
            return Response::error('You must provide either a slug or document_id. Use list-documents to find available documents.');
        }

        $document = Document::query()
            ->when(
                ! empty($validated['slug']),
                fn ($q) => $q->where('slug', $validated['slug']),
                fn ($q) => $q->where('id', $validated['document_id'])
            )
            ->first();

        if (! $document) {
            return Response::error('Document not found. Use list-documents to find available documents.');
        }

        $updates = [];

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
        }

        if (isset($validated['content'])) {
            $updates['content'] = $validated['content'];
        }

        if (array_key_exists('project_slug', $validated)) {
            if ($validated['project_slug'] === null || $validated['project_slug'] === '') {
                $updates['project_id'] = null;
            } else {
                $project = Project::query()->where('slug', $validated['project_slug'])->first();
                if (! $project) {
                    return Response::error('Project not found. Use list-projects to find available project slugs.');
                }
                $updates['project_id'] = $project->id;
            }
        }

        if (isset($validated['type'])) {
            $updates['type'] = DocumentType::from($validated['type']);
        }

        if (isset($validated['is_pinned'])) {
            $updates['is_pinned'] = $validated['is_pinned'];
        }

        if (empty($updates)) {
            return Response::error('No fields to update. Provide at least one field: title, content, project_slug, type, or is_pinned.');
        }

        $document->update($updates);
        $document->refresh();
        $document->load('project');

        $data = [
            'id' => $document->id,
            'slug' => $document->slug,
            'title' => $document->title,
            'type' => $document->type->value,
            'updated_at' => $document->updated_at->toDateTimeString(),
            'message' => 'Document updated successfully.',
        ];

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('The slug of the document to update. Use this OR document_id.'),
            'document_id' => $schema->integer()->description('The ID of the document to update. Use this OR slug.'),
            'title' => $schema->string()->description('New title for the document.'),
            'content' => $schema->string()->description('New markdown content for the document.'),
            'project_slug' => $schema->string()->description('Slug of the project to assign the document to. Set to empty string to remove from project.'),
            'type' => $schema->string()->enum(['prd', 'spec', 'decision', 'note', 'reference'])->description('New document type.'),
            'is_pinned' => $schema->boolean()->description('Whether to pin the document.'),
        ];
    }
}
