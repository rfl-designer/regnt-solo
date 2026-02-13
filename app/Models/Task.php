<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'estimated_minutes',
        'completed_at',
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
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'completed_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return BelongsToMany<DailyPlan, $this>
     */
    public function dailyPlans(): BelongsToMany
    {
        return $this->belongsToMany(DailyPlan::class)
            ->withPivot('sort_order', 'completed_at')
            ->withTimestamps();
    }

    /**
     * Scope to only inbox tasks.
     */
    public function scopeInbox(Builder $query): void
    {
        $query->where('status', TaskStatus::Inbox);
    }

    /**
     * Scope to only active (not done) tasks.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', TaskStatus::Done);
    }

    /**
     * Scope to filter by a specific status.
     */
    public function scopeByStatus(Builder $query, TaskStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope to only overdue tasks (due_date < today and not done).
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('due_date', '<', Carbon::today())
            ->where('status', '!=', TaskStatus::Done);
    }

    /**
     * Scope to only unassigned tasks (no project).
     */
    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('project_id');
    }

    /**
     * Scope to tasks completed this week (Monday to Sunday).
     */
    public function scopeDoneThisWeek(Builder $query): void
    {
        $query->where('status', TaskStatus::Done)
            ->whereBetween('completed_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ]);
    }

    /**
     * Check if the task is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && $this->status !== TaskStatus::Done;
    }

    /**
     * Check if the task has a running time entry.
     */
    public function isRunning(): bool
    {
        return $this->timeEntries()->whereNull('stopped_at')->exists();
    }

    /**
     * Mark the task as done.
     */
    public function markAsDone(): void
    {
        $this->update([
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);
    }
}
