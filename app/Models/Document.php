<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Document extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'title',
        'slug',
        'content',
        'type',
        'is_pinned',
        'is_context',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'is_pinned' => 'boolean',
            'is_context' => 'boolean',
        ];
    }

    /**
     * Boot the model and auto-generate slug from title.
     */
    protected static function booted(): void
    {
        static::creating(function (Document $document): void {
            if (empty($document->slug)) {
                $document->slug = static::generateUniqueSlug($document->title, $document->project_id);
            }
        });

        static::updating(function (Document $document): void {
            if ($document->isDirty('title') && ! $document->isDirty('slug')) {
                $document->slug = static::generateUniqueSlug($document->title, $document->project_id, $document->id);
            }
        });
    }

    /**
     * Generate a globally unique slug.
     */
    public static function generateUniqueSlug(string $title, ?int $projectId = null, ?int $excludeId = null): string
    {
        $slug = Str::slug($title);
        $originalSlug = $slug;
        $counter = 1;

        while (static::query()
            ->where('slug', $slug)
            ->when($excludeId, fn (Builder $q) => $q->where('id', '!=', $excludeId))
            ->exists()
        ) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get the project this document belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get a plain-text excerpt of the content.
     */
    public function excerpt(int $length = 200): string
    {
        return \App\Support\Markdown::excerpt($this->content ?? '', $length);
    }

    /**
     * Scope to documents for a specific project.
     */
    public function scopeForProject(Builder $query, int $projectId): void
    {
        $query->where('project_id', $projectId);
    }

    /**
     * Scope to global documents (no project).
     */
    public function scopeGlobal(Builder $query): void
    {
        $query->whereNull('project_id');
    }

    /**
     * Scope to pinned documents.
     */
    public function scopePinned(Builder $query): void
    {
        $query->where('is_pinned', true);
    }

    /**
     * Scope to context documents (available for AI assistants via MCP).
     */
    public function scopeContext(Builder $query): void
    {
        $query->where('is_context', true);
    }

    /**
     * Scope to filter by document type.
     */
    public function scopeByType(Builder $query, DocumentType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Scope to order documents: pinned first, then by sort_order, then by title.
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_pinned')
            ->orderBy('sort_order')
            ->orderBy('title');
    }
}
