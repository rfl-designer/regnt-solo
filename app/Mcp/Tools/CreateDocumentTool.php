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

class CreateDocumentTool extends Tool
{
    protected string $name = 'create-document';

    protected string $description = 'Creates a new document. Optionally assign to a project by slug.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'project_slug' => 'nullable|string|exists:projects,slug',
            'type' => ['nullable', 'string', Rule::enum(DocumentType::class)],
            'is_pinned' => 'nullable|boolean',
            'is_context' => 'nullable|boolean',
        ], [
            'title.required' => 'You must provide a title for the document.',
            'content.required' => 'You must provide content for the document.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'type.Illuminate\Validation\Rules\Enum' => 'Invalid type. Valid values: prd, spec, decision, note, reference.',
        ]);

        $projectId = null;
        if (! empty($validated['project_slug'])) {
            $projectId = Project::query()->where('slug', $validated['project_slug'])->value('id');
        }

        $document = Document::create([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'project_id' => $projectId,
            'type' => $validated['type'] ?? DocumentType::Note,
            'is_pinned' => $validated['is_pinned'] ?? false,
            'is_context' => $validated['is_context'] ?? false,
        ]);

        $document->load('project');

        $data = [
            'id' => $document->id,
            'slug' => $document->slug,
            'title' => $document->title,
            'type' => $document->type->value,
            'project_slug' => $document->project?->slug,
            'message' => 'Document created successfully.',
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
            'title' => $schema->string()->description('The title of the document.')->required(),
            'content' => $schema->string()->description('The markdown content of the document.')->required(),
            'project_slug' => $schema->string()->description('Slug of the project to assign the document to. Leave empty for a global document.'),
            'type' => $schema->string()->enum(['prd', 'spec', 'decision', 'note', 'reference'])->description('Document type. Default: note.'),
            'is_pinned' => $schema->boolean()->description('Whether to pin the document. Default: false.'),
            'is_context' => $schema->boolean()->description('Whether this document should be available as AI context via MCP. Default: false.'),
        ];
    }
}
