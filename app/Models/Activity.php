<?php

namespace App\Models;

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\ServiceClass;
use App\Observers\ActivityObserver;
use App\Observers\ActivityRealtimeObserver;
use Carbon\Carbon;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[ObservedBy([ActivityObserver::class, ActivityRealtimeObserver::class])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'project_id',
        'client_id',
        'parent_id',
        'title',
        'slug',
        'description',
        'spec',
        'status',
        'priority',
        'service_class',
        // `waiting_since` is deliberately NOT fillable: it must only ever
        // be stamped by ActivityObserver::handleWaitingState() with now(),
        // never accepted from caller input (Kanban, Task Modal, MCP tools),
        // so nothing can forge the "desde quando" timestamp on entry into
        // a waiting status.
        'waiting_for',
        // `emergency_since` is deliberately NOT fillable, for the same
        // reason as `waiting_since` above: only
        // ActivityObserver::handleEmergencyState() may stamp it, so the
        // "idade da Emergência ativa" can't be forged by caller input.
        'emergency_reason',
        'due_date',
        'estimated_minutes',
        'completed_at',
        'sort_order',
        'pr_url',
        'session_prompt',
        'session_result',
        'recurring_task_id',
        'task_template_id',
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
            'type' => ActivityType::class,
            'status' => ActivityStatus::class,
            'priority' => ActivityPriority::class,
            'service_class' => ServiceClass::class,
            'waiting_since' => 'datetime',
            'emergency_since' => 'datetime',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Activity $activity): void {
            if ($activity->type === ActivityType::Epic && empty($activity->slug)) {
                $activity->slug = Str::slug($activity->title);
            }
        });
    }

    /**
     * Get the project this activity belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the client this activity is directly linked to (only meaningful
     * when the activity has no project).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the effective client for this activity: the project's client if
     * the activity has a project, otherwise the directly linked client.
     */
    protected function effectiveClient(): Attribute
    {
        return Attribute::get(function (): ?Client {
            if ($this->project_id !== null) {
                return $this->relationLoaded('project')
                    ? $this->project?->client
                    : $this->project()->first()?->client;
            }

            return $this->relationLoaded('client') ? $this->client : $this->client()->first();
        });
    }

    /**
     * Get the parent activity (the tree edge).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'parent_id');
    }

    /**
     * Get the child activities.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Activity::class, 'parent_id');
    }

    /**
     * Get the recurring task this activity was created from.
     */
    public function recurringTask(): BelongsTo
    {
        return $this->belongsTo(RecurringTask::class);
    }

    /**
     * Get the template this activity was created from.
     */
    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    /**
     * Get the commits associated with this activity.
     */
    public function commits(): HasMany
    {
        return $this->hasMany(ActivityCommit::class);
    }

    /**
     * Get the time entries for this activity.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get the status changes for this activity, ordered chronologically.
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ActivityStatusChange::class)->orderBy('changed_at');
    }

    /**
     * Get the daily plans this activity belongs to.
     */
    public function dailyPlans(): BelongsToMany
    {
        return $this->belongsToMany(DailyPlan::class, 'daily_plan_activity', 'activity_id', 'daily_plan_id')
            ->withPivot('sort_order', 'completed_at')
            ->withTimestamps();
    }

    /**
     * Get the stakeholder issues linked to this activity.
     */
    public function stakeholderIssues(): HasMany
    {
        return $this->hasMany(StakeholderIssue::class);
    }

    /**
     * Get the client-facing label derived from the parent (only meaningful for Issues).
     *
     * Issue whose parent is an Epic -> Fatia; whose parent is an Issue -> Follow-up;
     * with no parent -> Avulsa. Other types fall back to their type label.
     */
    public function derivedLabel(): string
    {
        if ($this->type !== ActivityType::Issue) {
            return $this->type->label();
        }

        if ($this->parent_id === null) {
            return 'Avulsa';
        }

        $parent = $this->relationLoaded('parent') ? $this->parent : $this->parent()->first();

        return $parent?->type === ActivityType::Epic ? 'Fatia' : 'Follow-up';
    }

    /**
     * Get the progress percentage (done children / total children).
     */
    protected function progress(): Attribute
    {
        return Attribute::get(function (): int {
            $children = $this->relationLoaded('children')
                ? $this->children
                : $this->children()->get();

            if ($children->isEmpty()) {
                return 0;
            }

            $done = $children->filter(fn (Activity $a): bool => $a->status === ActivityStatus::Done)->count();

            return (int) round(($done / $children->count()) * 100);
        });
    }

    /**
     * Get the total time spent on this activity (in minutes).
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
            foreach (ActivityStatus::cases() as $status) {
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
     * Get the duration (in minutes) the activity has been in its current status.
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
     * Scope to a specific type.
     */
    public function scopeOfType(Builder $query, ActivityType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Scope to only epics.
     */
    public function scopeEpics(Builder $query): void
    {
        $query->where('type', ActivityType::Epic);
    }

    /**
     * Scope to only issues.
     */
    public function scopeIssues(Builder $query): void
    {
        $query->where('type', ActivityType::Issue);
    }

    /**
     * Scope to only personal tasks.
     */
    public function scopeTasks(Builder $query): void
    {
        $query->where('type', ActivityType::Task);
    }

    /**
     * Scope to only drafts.
     */
    public function scopeDrafts(Builder $query): void
    {
        $query->where('type', ActivityType::Draft);
    }

    /**
     * Scope to actionable leaf items: issues plus atomic epics (epics without children).
     */
    public function scopeLeaf(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->where('type', ActivityType::Issue)
                ->orWhere(function (Builder $epic): void {
                    $epic->where('type', ActivityType::Epic)
                        ->whereDoesntHave('children');
                });
        });
    }

    /**
     * The instance-level counterpart of {@see scopeLeaf()}: whether this
     * activity is one of the items the board actually shows as a card.
     * Both the WIP limit guard and the "n/2" indicator answer "how many
     * items are in Fazendo" through this same definition, so the number
     * the user sees is exactly the number the guard counts.
     */
    public function isLeaf(): bool
    {
        return match ($this->type) {
            ActivityType::Issue => true,
            ActivityType::Epic => ! $this->children()->exists(),
            default => false,
        };
    }

    /**
     * Scope to the Emergência currently holding the board's single
     * emergency slot: classified as Emergência and not yet concluded.
     * Done Emergências are history and drafts (null status) were never on
     * the board, so neither occupies the slot.
     */
    public function scopeActiveEmergency(Builder $query): void
    {
        $query->where('service_class', ServiceClass::Emergency)
            ->whereNotNull('status')
            ->where('status', '!=', ActivityStatus::Done);
    }

    /**
     * Scope to schedulable leaf items: issues, personal tasks plus atomic epics.
     */
    public function scopeSchedulable(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereIn('type', [ActivityType::Issue, ActivityType::Task])
                ->orWhere(function (Builder $epic): void {
                    $epic->where('type', ActivityType::Epic)
                        ->whereDoesntHave('children');
                });
        });
    }

    /**
     * Scope to only inbox activities.
     */
    public function scopeInbox(Builder $query): void
    {
        $query->where('status', ActivityStatus::Inbox);
    }

    /**
     * Scope to only active (not done) activities.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', ActivityStatus::Done);
    }

    /**
     * Scope to filter by a specific status.
     */
    public function scopeByStatus(Builder $query, ActivityStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope to exclude activities currently in a waiting status (Aguardando
     * aprovação, Esperando, Aguardando validação). Used by daily planning
     * surfaces (suggestions, carry-over, available tasks) so items waiting
     * on someone don't get suggested for today's plan — items already in a
     * plan stay visible regardless, only entry into new suggestions is
     * gated here.
     */
    public function scopeNotWaiting(Builder $query): void
    {
        $waitingValues = array_map(
            fn (ActivityStatus $status): string => $status->value,
            array_filter(ActivityStatus::cases(), fn (ActivityStatus $status): bool => $status->isWaiting())
        );

        $query->whereNotIn('status', $waitingValues);
    }

    /**
     * Scope to only overdue activities.
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::today())
            ->where('status', '!=', ActivityStatus::Done);
    }

    /**
     * Scope to only unassigned activities (no project).
     */
    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('project_id');
    }

    /**
     * Scope to activities for a specific project.
     */
    public function scopeForProject(Builder $query, int $projectId): void
    {
        $query->where('project_id', $projectId);
    }

    /**
     * Scope to activities completed this week.
     */
    public function scopeDoneThisWeek(Builder $query): void
    {
        $query->where('status', ActivityStatus::Done)
            ->whereBetween('completed_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ]);
    }

    /**
     * The canonical service-class ordering, as a `CASE` SQL fragment:
     * Emergência -> Data fixa -> Padrão -> Intangível. This is the single
     * ordering axis for activities and must be reused everywhere a query
     * needs to rank by service class, rather than duplicated inline.
     */
    public const string SERVICE_CLASS_ORDER_SQL = "CASE service_class WHEN 'emergency' THEN 0 WHEN 'fixed_date' THEN 1 WHEN 'standard' THEN 2 WHEN 'intangible' THEN 3 END";

    /**
     * Scope to order by the canonical service-class ranking (no tie-breaker).
     */
    public function scopeOrderByServiceClass(Builder $query): void
    {
        $query->orderByRaw(self::SERVICE_CLASS_ORDER_SQL);
    }

    /**
     * Scope to order by service class then sort_order.
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByServiceClass()
            ->orderBy('sort_order');
    }

    /**
     * Check if this activity was created from a recurring task.
     */
    public function isFromRecurring(): bool
    {
        return $this->recurring_task_id !== null;
    }

    /**
     * Check if this activity was created from a template.
     */
    public function isFromTemplate(): bool
    {
        return $this->task_template_id !== null;
    }

    /**
     * Check if this activity has a parent activity.
     */
    public function hasParent(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Check if the activity is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isBefore(Carbon::today())
            && $this->status !== ActivityStatus::Done;
    }

    /**
     * Check if the activity is currently in a waiting status.
     */
    public function isWaiting(): bool
    {
        return $this->status !== null && $this->status->isWaiting();
    }

    /**
     * Get the number of whole days the activity has been waiting, for the
     * "⏳ {quem} · há X dias" badge. Returns 0 when not waiting or when
     * waiting_since hasn't been stamped yet.
     */
    public function waitingDays(): int
    {
        if (! $this->isWaiting() || $this->waiting_since === null) {
            return 0;
        }

        return (int) $this->waiting_since->diffInDays(now());
    }

    /**
     * Whether this activity is the Emergência currently occupying the
     * board's single emergency slot (see {@see scopeActiveEmergency()}).
     */
    public function isActiveEmergency(): bool
    {
        return $this->service_class === ServiceClass::Emergency
            && $this->status !== null
            && $this->status !== ActivityStatus::Done;
    }

    /**
     * Get the number of whole days since the activity was classified as
     * Emergência — the "idade" shown when a second Emergência is refused.
     * Returns 0 when it isn't an Emergência or `emergency_since` hasn't
     * been stamped yet.
     */
    public function emergencyDays(): int
    {
        if ($this->service_class !== ServiceClass::Emergency || $this->emergency_since === null) {
            return 0;
        }

        return (int) $this->emergency_since->diffInDays(now());
    }

    /**
     * Check if the activity has a running time entry.
     */
    public function isRunning(): bool
    {
        if ($this->relationLoaded('timeEntries')) {
            return $this->timeEntries->contains(fn (TimeEntry $entry): bool => $entry->stopped_at === null);
        }

        return $this->timeEntries()->running()->exists();
    }

    /**
     * Get the count of completed children.
     */
    public function completedTasksCount(): int
    {
        if ($this->relationLoaded('children')) {
            return $this->children->filter(fn (Activity $a): bool => $a->status === ActivityStatus::Done)->count();
        }

        return $this->children()->where('status', ActivityStatus::Done)->count();
    }

    /**
     * Get the total children count.
     */
    public function tasksCount(): int
    {
        if ($this->relationLoaded('children')) {
            return $this->children->count();
        }

        return $this->children()->count();
    }

    /**
     * Get the total number of commits for this activity.
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
     * Get the total focus minutes for this activity.
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
     * Check if this activity is a session task (has a session prompt).
     */
    public function isSessionTask(): bool
    {
        return $this->session_prompt !== null;
    }

    /**
     * Get a summary of the session data for this activity.
     *
     * @return array{is_session: bool, prompt: string|null, result: string|null, pr_url: string|null, commits_count: int, files_changed: int, status: string|null}
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
            'status' => $this->status?->value,
        ];
    }

    /**
     * Mark the activity as done.
     */
    public function markAsDone(): void
    {
        TimeEntry::query()
            ->where('activity_id', $this->id)
            ->running()
            ->update(['stopped_at' => now()]);

        $this->update([
            'status' => ActivityStatus::Done,
            'completed_at' => now(),
        ]);
    }

    /**
     * Start a timer for this activity, stopping any other running timers.
     *
     * For leaf work items (Issue/Task) the status moves to "doing" unless it
     * is already "doing" or "done"; Epics keep their manual status.
     */
    public function startTimer(bool $focus = false): TimeEntry
    {
        TimeEntry::stopAllRunning();

        if ($this->type !== ActivityType::Epic
            && ! in_array($this->status, [ActivityStatus::Doing, ActivityStatus::Done], true)) {
            $this->update(['status' => ActivityStatus::Doing]);
        }

        return TimeEntry::create([
            'activity_id' => $this->id,
            'started_at' => now(),
            'is_focus_session' => $focus,
        ]);
    }

    /**
     * Stop the running timer for this activity.
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
     * Get the currently running time entry for this activity.
     */
    public function runningEntry(): ?TimeEntry
    {
        if ($this->relationLoaded('timeEntries')) {
            return $this->timeEntries->first(fn (TimeEntry $entry): bool => $entry->stopped_at === null);
        }

        return $this->timeEntries()->running()->latest('started_at')->first();
    }
}
