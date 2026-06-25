<?php

namespace App\Models;

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\RecurrenceFrequency;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringTask extends Model
{
    /** @use HasFactory<\Database\Factories\RecurringTaskFactory> */
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
        'frequency',
        'day_of_week',
        'day_of_month',
        'priority',
        'next_run',
        'last_run',
        'is_active',
        'estimated_minutes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => RecurrenceFrequency::class,
            'priority' => ActivityPriority::class,
            'next_run' => 'date',
            'last_run' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the project this recurring task belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the activities created from this recurring task.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Scope to only active recurring tasks.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to recurring tasks due today or earlier.
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('next_run', '<=', Carbon::today());
    }

    /**
     * Check if this recurring task is due.
     */
    public function isDue(): bool
    {
        return $this->is_active && $this->next_run->lte(Carbon::today());
    }

    /**
     * Create an activity from this recurring task.
     */
    public function createTask(): Activity
    {
        return Activity::create([
            'type' => ActivityType::Task,
            'project_id' => $this->project_id,
            'recurring_task_id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => ActivityStatus::Todo,
            'priority' => $this->priority,
            'due_date' => Carbon::today(),
            'estimated_minutes' => $this->estimated_minutes,
        ]);
    }

    /**
     * Calculate and update the next run date based on the frequency.
     */
    public function calculateNextRun(): Carbon
    {
        $baseDate = $this->last_run ? Carbon::parse($this->last_run) : Carbon::today();

        return match ($this->frequency) {
            RecurrenceFrequency::Daily => $baseDate->copy()->addDay(),
            RecurrenceFrequency::Weekdays => $this->getNextWeekday($baseDate),
            RecurrenceFrequency::Weekly => $this->getNextWeeklyDate($baseDate),
            RecurrenceFrequency::Biweekly => $baseDate->copy()->addWeeks(2),
            RecurrenceFrequency::Monthly => $this->getNextMonthlyDate($baseDate),
        };
    }

    /**
     * Process this recurring task: create task and update next_run.
     */
    public function process(): Activity
    {
        $task = $this->createTask();

        $this->update([
            'last_run' => Carbon::today(),
            'next_run' => $this->calculateNextRun(),
        ]);

        return $task;
    }

    /**
     * Get the next weekday after the given date.
     */
    private function getNextWeekday(Carbon $date): Carbon
    {
        $next = $date->copy()->addDay();

        while ($next->isWeekend()) {
            $next->addDay();
        }

        return $next;
    }

    /**
     * Get the next weekly date based on day_of_week.
     */
    private function getNextWeeklyDate(Carbon $date): Carbon
    {
        $next = $date->copy()->addWeek();

        if ($this->day_of_week !== null) {
            $next->setDaysFromStartOfWeek($this->day_of_week);

            if ($next->lte($date)) {
                $next->addWeek();
            }
        }

        return $next;
    }

    /**
     * Get the next monthly date based on day_of_month.
     */
    private function getNextMonthlyDate(Carbon $date): Carbon
    {
        $next = $date->copy()->addMonth();

        if ($this->day_of_month !== null) {
            $day = min($this->day_of_month, $next->daysInMonth);
            $next->setDay($day);

            if ($next->lte($date)) {
                $next->addMonth();
                $day = min($this->day_of_month, $next->daysInMonth);
                $next->setDay($day);
            }
        }

        return $next;
    }
}
