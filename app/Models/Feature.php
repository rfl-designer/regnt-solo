<?php

namespace App\Models;

use App\Enums\FeaturePriority;
use App\Enums\FeatureStatus;
use App\Enums\TaskStatus;
use App\Observers\FeatureRealtimeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[ObservedBy([FeatureRealtimeObserver::class])]
class Feature extends Model
{
    /** @use HasFactory<\Database\Factories\FeatureFactory> */
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
        'spec',
        'priority',
        'due_date',
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
            'priority' => FeaturePriority::class,
            'due_date' => 'date',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Feature $feature): void {
            if (empty($feature->slug)) {
                $feature->slug = Str::slug($feature->title);
            }
        });
    }

    /**
     * Get the project this feature belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the tasks for this feature.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get the time entries for this feature.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get the stakeholder issues linked to this feature.
     */
    public function stakeholderIssues(): HasMany
    {
        return $this->hasMany(StakeholderIssue::class);
    }

    /**
     * Get the computed status based on tasks.
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): FeatureStatus {
            $tasks = $this->relationLoaded('tasks')
                ? $this->tasks
                : $this->tasks()->get();

            if ($tasks->isEmpty()) {
                return FeatureStatus::Draft;
            }

            $statuses = $tasks->pluck('status');

            if ($statuses->every(fn (TaskStatus $s): bool => $s === TaskStatus::Done)) {
                return FeatureStatus::Done;
            }

            if ($statuses->contains(TaskStatus::Doing)) {
                return FeatureStatus::Doing;
            }

            if ($statuses->contains(TaskStatus::Todo)) {
                return FeatureStatus::Todo;
            }

            return FeatureStatus::Backlog;
        });
    }

    /**
     * Get the progress percentage (completed tasks / total tasks).
     */
    protected function progress(): Attribute
    {
        return Attribute::get(function (): int {
            $tasks = $this->relationLoaded('tasks')
                ? $this->tasks
                : $this->tasks()->get();

            if ($tasks->isEmpty()) {
                return 0;
            }

            $done = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Done)->count();

            return (int) round(($done / $tasks->count()) * 100);
        });
    }

    /**
     * Get the total time spent on this feature (in minutes).
     */
    protected function totalTime(): Attribute
    {
        return Attribute::get(function (): float {
            $entries = $this->relationLoaded('timeEntries')
                ? $this->timeEntries
                : $this->timeEntries()->get();

            return $entries
                ->filter(fn (TimeEntry $e): bool => $e->stopped_at !== null)
                ->sum('duration_minutes');
        });
    }

    /**
     * Get the count of completed tasks.
     */
    public function completedTasksCount(): int
    {
        if ($this->relationLoaded('tasks')) {
            return $this->tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Done)->count();
        }

        return $this->tasks()->where('status', TaskStatus::Done)->count();
    }

    /**
     * Get the total tasks count.
     */
    public function tasksCount(): int
    {
        if ($this->relationLoaded('tasks')) {
            return $this->tasks->count();
        }

        return $this->tasks()->count();
    }

    /**
     * Check if the feature has a running timer.
     */
    public function isRunning(): bool
    {
        if ($this->relationLoaded('timeEntries')) {
            return $this->timeEntries->contains(fn (TimeEntry $entry): bool => $entry->stopped_at === null);
        }

        return $this->timeEntries()->running()->exists();
    }

    /**
     * Start a timer for this feature, stopping any other running timers.
     */
    public function startTimer(bool $focus = false): TimeEntry
    {
        TimeEntry::stopAllRunning();

        return TimeEntry::create([
            'feature_id' => $this->id,
            'started_at' => now(),
            'is_focus_session' => $focus,
        ]);
    }

    /**
     * Stop the running timer for this feature.
     */
    public function stopTimer(?string $notes = null): ?TimeEntry
    {
        $entry = $this->timeEntries()->running()->latest('started_at')->first();

        if ($entry === null) {
            return null;
        }

        $entry->update([
            'stopped_at' => now(),
            'notes' => $notes,
        ]);

        return $entry->fresh();
    }

    /**
     * Get the currently running time entry for this feature.
     */
    public function runningEntry(): ?TimeEntry
    {
        if ($this->relationLoaded('timeEntries')) {
            return $this->timeEntries->first(fn (TimeEntry $entry): bool => $entry->stopped_at === null);
        }

        return $this->timeEntries()->running()->latest('started_at')->first();
    }

    /**
     * Scope to features for a specific project.
     */
    public function scopeForProject(Builder $query, int $projectId): void
    {
        $query->where('project_id', $projectId);
    }

    /**
     * Scope to features without a project.
     */
    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('project_id');
    }

    /**
     * Scope to order by priority then sort_order.
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END")
            ->orderBy('sort_order');
    }
}
