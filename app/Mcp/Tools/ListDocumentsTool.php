<?php

namespace App\Mcp\Tools;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListDocumentsTool extends Tool
{
    protected string $name = 'list-documents';

    protected string $description = 'Lists documents. By default returns only context documents (is_context=true) with truncated content. Use include_all=true to return all documents.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_slug' => 'nullable|string|exists:projects,slug',
            'type' => ['nullable', 'string', Rule::enum(DocumentType::class)],
            'limit' => 'nullable|integer|min:1|max:100',
            'include_all' => 'nullable|boolean',
        ], [
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'type.Illuminate\Validation\Rules\Enum' => 'Invalid type. Valid values: prd, spec, decision, note, reference.',
        ]);

        $limit = $validated['limit'] ?? 20;
        $includeAll = $validated['include_all'] ?? false;

        $query = Document::query()
            ->with('project')
            ->orderByDesc('is_pinned')
            ->orderBy('sort_order')
            ->orderByDesc('updated_at');

        if (! $includeAll) {
            $query->context();
        }

        if (! empty($validated['project_slug'])) {
            $projectId = Project::query()->where('slug', $validated['project_slug'])->value('id');
            $query->forProject($projectId);
        }

        if (! empty($validated['type'])) {
            $query->byType(DocumentType::from($validated['type']));
        }

        $documents = $query->limit($limit)->get();

        $data = $documents->map(fn (Document $doc) => [
            'id' => $doc->id,
            'title' => $doc->title,
            'slug' => $doc->slug,
            'type' => $doc->type->value,
            'project_slug' => $doc->project?->slug,
            'project_name' => $doc->project?->name,
            'content_preview' => $this->truncateContent($doc->content ?? ''),
            'is_pinned' => $doc->is_pinned,
            'is_context' => $doc->is_context,
            'updated_at' => $doc->updated_at->toDateTimeString(),
        ])->all();

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
            'project_slug' => $schema->string()->description('Filter documents by project slug. Optional.'),
            'type' => $schema->string()->enum(['prd', 'spec', 'decision', 'note', 'reference'])->description('Filter documents by type. Optional.'),
            'limit' => $schema->integer()->description('Maximum number of documents to return. Default: 20, max: 100.'),
            'include_all' => $schema->boolean()->description('When true, returns all documents regardless of context flag. Default: false (only context documents).'),
        ];
    }

    /**
     * Truncate content to first 255 and last 255 characters.
     */
    private function truncateContent(string $content): string
    {
        if (Str::length($content) <= 510) {
            return $content;
        }

        return Str::limit($content, 255, '')."\n[...]\n".Str::substr($content, -255);
    }
}
