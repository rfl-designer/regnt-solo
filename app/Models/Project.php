<?php

namespace App\Models;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'color',
        'emoji',
        'status',
        'priority',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'priority' => ProjectPriority::class,
        ];
    }

    /**
     * Get the features for this project.
     */
    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }

    /**
     * Get the tasks for this project.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get the documents for this project.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Get the stakeholders for this project.
     */
    public function stakeholders(): HasMany
    {
        return $this->hasMany(Stakeholder::class);
    }

    /**
     * Get stakeholder issues linked to this project.
     */
    public function stakeholderIssues(): HasMany
    {
        return $this->hasMany(StakeholderIssue::class);
    }

    /**
     * Scope to only backlog projects.
     */
    public function scopeBacklog(Builder $query): void
    {
        $query->where('status', ProjectStatus::Backlog);
    }

    /**
     * Scope to only active projects.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ProjectStatus::Active);
    }

    /**
     * Scope to only paused projects.
     */
    public function scopePaused(Builder $query): void
    {
        $query->where('status', ProjectStatus::Paused);
    }

    /**
     * Scope to only done projects.
     */
    public function scopeDone(Builder $query): void
    {
        $query->where('status', ProjectStatus::Done);
    }

    /**
     * Scope to only maintenance projects.
     */
    public function scopeMaintenance(Builder $query): void
    {
        $query->where('status', ProjectStatus::Maintenance);
    }

    /**
     * Scope to only archived projects.
     */
    public function scopeArchived(Builder $query): void
    {
        $query->where('status', ProjectStatus::Archived);
    }

    /**
     * Scope to order by priority (desc) then name (asc).
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END")
            ->orderBy('name');
    }
}
