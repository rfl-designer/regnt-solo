<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DailyPlan extends Model
{
    /** @use HasFactory<\Database\Factories\DailyPlanFactory> */
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
     * @return BelongsToMany<Task, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class)
            ->withPivot('sort_order', 'completed_at')
            ->withTimestamps();
    }

    /**
     * Get or create a DailyPlan for the given date.
     */
    public static function getOrCreateForDate(Carbon|string $date): self
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return static::query()
            ->whereDate('date', $date)
            ->first() ?? static::create(['date' => $date->toDateString()]);
    }

    /**
     * Calculate the completion rate as a percentage (0-100).
     */
    public function completionRate(): float
    {
        $total = $this->tasks()->count();

        if ($total === 0) {
            return 0;
        }

        $completed = $this->tasks()->wherePivotNotNull('completed_at')->count();

        return round(($completed / $total) * 100, 2);
    }

    /**
     * Get tasks from this plan that are not yet completed.
     *
     * @return Collection<int, Task>
     */
    public function incompleteTasks(): Collection
    {
        return $this->tasks()->wherePivotNull('completed_at')->get();
    }
}
