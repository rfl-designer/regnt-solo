<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use Carbon\Carbon;
use Database\Factories\DailyPlanFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DailyPlan extends Model
{
    /** @use HasFactory<DailyPlanFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * Get the tasks for this daily plan.
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'daily_plan_activity', 'daily_plan_id', 'activity_id')
            ->withPivot('sort_order', 'completed_at')
            ->withTimestamps();
    }

    /**
     * Get or create a daily plan for the given date.
     */
    public static function getOrCreateForDate(Carbon $date): static
    {
        $dateString = $date->toDateString();

        return static::query()->whereDate('date', $dateString)->first()
            ?? static::create(['date' => $dateString]);
    }

    /**
     * Get the completion rate as a percentage (0-100).
     */
    public function completionRate(): float
    {
        if ($this->relationLoaded('tasks')) {
            $total = $this->tasks->count();

            if ($total === 0) {
                return 0.0;
            }

            $completed = $this->tasks->filter(fn (Activity $t): bool => $t->pivot->completed_at !== null)->count();

            return round(($completed / $total) * 100, 2);
        }

        $total = $this->tasks()->count();

        if ($total === 0) {
            return 0.0;
        }

        $completed = $this->tasks()->wherePivotNotNull('completed_at')->count();

        return round(($completed / $total) * 100, 2);
    }

    /**
     * Get the incomplete tasks for this daily plan, for carry-over into a
     * later day. Excludes tasks that have status Done (even if not marked
     * complete in the plan) and tasks currently in a waiting status (issue
     * #142) — an item waiting on someone isn't something to carry over and
     * re-suggest; it stays visible in its own day's plan with its waiting
     * badge instead.
     *
     * @return Collection<int, Activity>
     */
    public function incompleteTasks(): Collection
    {
        return $this->tasks()
            ->wherePivotNull('completed_at')
            ->where('status', '!=', ActivityStatus::Done)
            ->notWaiting()
            ->get();
    }
}
