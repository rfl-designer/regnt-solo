<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Observers\TaskObserver;
use App\Observers\TaskRealtimeObserver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([TaskObserver::class, TaskRealtimeObserver::class])]
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
        'feature_id',
        'recurring_task_id',
        'task_template_id',
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'estimated_minutes',
        'completed_at',
        'sort_order',
        'pr_url',
        'session_prompt',
        'session_result',
        'github_issue_number',
        'github_synced_hash',
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
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the project this task belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the feature this task belongs to.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    /**
     * Get the recurring task this task was created from.
     */
    public function recurringTask(): BelongsTo
    {
        return $this->belongsTo(RecurringTask::class);
    }

    /**
     * Get the template this task was created from.
     */
    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    /**
     * Check if this task was created from a recurring task.
     */
    public function isFromRecurring(): bool
    {
        return $this->recurring_task_id !== null;
    }

    /**
     * Check if this task was created from a template.
     */
    public function isFromTemplate(): bool
    {
        return $this->task_template_id !== null;
    }

    /**
     * Check if this task belongs to a feature.
     */
    public function isFromFeature(): bool
    {
        return $this->feature_id !== null;
    }

    /**
     * Get the commits associated with this task.
     */
    public function commits(): HasMany
    {
        return $this->hasMany(TaskCommit::class);
    }

    /**
     * Get the time entries for this task.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get the status changes for this task, ordered chronologically.
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(TaskStatusChange::class)->orderBy('changed_at');
    }

    /**
     * Get the daily plans this task belongs to.
     */
    public function dailyPlans(): BelongsToMany
    {
        return $this->belongsToMany(DailyPlan::class)
            ->withPivot('sort_order', 'completed_at')
            ->withTimestamps();
    }

    /**
     * Get the accumulated time in each status (in minutes).
     *
     * @return array<string, float>
     */
    protected function timeInStatus(): Attribute
    {
        return Attribute::get(function (): array {
            $changes = $this->relationLoaded('statusChanges')
                ? $this->statusChanges->sortBy('changed_at')->values()
                : $this->statusChanges()->orderBy('changed_at')->get();

            $times = [];
            foreach (TaskStatus::cases() as $status) {
                $times[$status->value] = 0.0;
            }

            if ($changes->isEmpty()) {
                return $times;
            }

            for ($i = 0; $i < $changes->count(); $i++) {
                $change = $changes[$i];
                $statusValue = $change->to_status->value;
                $start = $change->changed_at;

                $end = $i + 1 < $changes->count()
                    ? $changes[$i + 1]->changed_at
                    : now();

                $times[$statusValue] += round($start->diffInMinutes($end), 2);
            }

            return $times;
        });
    }

    /**
     * Get the duration (in minutes) the task has been in its current status.
     */
    protected function currentStatusDuration(): Attribute
    {
        return Attribute::get(function (): float {
            $lastChange = $this->relationLoaded('statusChanges')
                ? $this->statusChanges->sortByDesc('changed_at')->first()
                : $this->statusChanges()->orderByDesc('changed_at')->first();

            if ($lastChange === null) {
                return 0.0;
            }

            return round($lastChange->changed_at->diffInMinutes(now()), 2);
        });
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
     * Scope to only overdue tasks.
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::today())
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
     * Scope to tasks completed this week.
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
            && $this->due_date->isBefore(Carbon::today())
            && $this->status !== TaskStatus::Done;
    }

    /**
     * Check if the task has a running time entry.
     */
    public function isRunning(): bool
    {
        if ($this->relationLoaded('timeEntries')) {
            return $this->timeEntries->contains(fn (TimeEntry $entry): bool => $entry->stopped_at === null);
        }

        return $this->timeEntries()->running()->exists();
    }

    /**
     * Get the total number of commits for this task.
     */
    public function commitCount(): int
    {
        if ($this->relationLoaded('commits')) {
            return $this->commits->count();
        }

        return $this->commits()->count();
    }

    /**
     * Get the total number of files changed across all commits.
     */
    public function totalFilesChanged(): int
    {
        if ($this->relationLoaded('commits')) {
            return (int) $this->commits->sum('files_changed');
        }

        return (int) $this->commits()->sum('files_changed');
    }

    /**
     * Get the total focus minutes for this task.
     */
    public function totalFocusMinutes(): float
    {
        if ($this->relationLoaded('timeEntries')) {
            return $this->timeEntries
                ->where('is_focus_session', true)
                ->whereNotNull('stopped_at')
                ->sum('duration_minutes');
        }

        return $this->timeEntries()
            ->focusSessions()
            ->whereNotNull('stopped_at')
            ->get()
            ->sum('duration_minutes');
    }

    /**
     * Check if this task is a session task (has a session prompt).
     */
    public function isSessionTask(): bool
    {
        return $this->session_prompt !== null;
    }

    /**
     * Get a summary of the session data for this task.
     *
     * @return array{is_session: bool, prompt: string|null, result: string|null, pr_url: string|null, commits_count: int, files_changed: int, status: string}
     */
    public function sessionSummary(): array
    {
        return [
            'is_session' => $this->isSessionTask(),
            'prompt' => $this->session_prompt,
            'result' => $this->session_result,
            'pr_url' => $this->pr_url,
            'commits_count' => $this->commitCount(),
            'files_changed' => $this->totalFilesChanged(),
            'status' => $this->status->value,
        ];
    }

    /**
     * Mark the task as done.
     */
    public function markAsDone(): void
    {
        TimeEntry::query()
            ->where('task_id', $this->id)
            ->running()
            ->update(['stopped_at' => now()]);

        $this->update([
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);
    }

    /**
     * Start a timer for this task, stopping any other running timers.
     * Automatically changes status to "doing" if not already "doing" or "done".
     */
    public function startTimer(bool $focus = false): TimeEntry
    {
        TimeEntry::stopAllRunning();

        if (! in_array($this->status, [TaskStatus::Doing, TaskStatus::Done], true)) {
            $this->update(['status' => TaskStatus::Doing]);
        }

        return TimeEntry::create([
            'task_id' => $this->id,
            'started_at' => now(),
            'is_focus_session' => $focus,
        ]);
    }
}
