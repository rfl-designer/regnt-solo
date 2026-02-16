<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteDocumentTool extends Tool
{
    protected string $name = 'delete-document';

    protected string $description = 'Permanently deletes a document. This action cannot be undone.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'slug' => 'nullable|string',
            'document_id' => 'nullable|integer',
        ], [
            'slug.string' => 'Slug must be a valid string.',
            'document_id.integer' => 'Document ID must be a valid integer.',
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

        $title = $document->title;
        $document->delete();

        $data = [
            'message' => "Document '{$title}' deleted successfully.",
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
            'slug' => $schema->string()->description('The slug of the document to delete. Use this OR document_id.'),
            'document_id' => $schema->integer()->description('The ID of the document to delete. Use this OR slug.'),
        ];
    }
}
