<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    /** @use HasFactory<\Database\Factories\TimeEntryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'started_at',
        'stopped_at',
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
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    /**
     * Get the duration in minutes between started_at and stopped_at.
     * If stopped_at is null (running), calculates from started_at to now.
     */
    protected function durationMinutes(): Attribute
    {
        return Attribute::make(
            get: fn (): int => (int) $this->started_at->diffInMinutes(
                $this->stopped_at ?? Carbon::now()
            ),
        );
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Scope to only running entries (stopped_at is null).
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereNull('stopped_at');
    }

    /**
     * Scope to entries for a given date.
     */
    public function scopeForDate(Builder $query, Carbon|string $date): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        $query->whereDate('started_at', $date->toDateString());
    }

    /**
     * Scope to entries from the current week (Monday to Sunday).
     */
    public function scopeForWeek(Builder $query): void
    {
        $query->whereBetween('started_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek(),
        ]);
    }

    /**
     * Scope to entries for a given project via the task relationship.
     */
    public function scopeForProject(Builder $query, int $projectId): void
    {
        $query->whereHas('task', function (Builder $q) use ($projectId): void {
            $q->where('project_id', $projectId);
        });
    }

    /**
     * Stop all currently running entries by setting stopped_at to now.
     */
    public static function stopAllRunning(): int
    {
        return static::query()
            ->whereNull('stopped_at')
            ->update(['stopped_at' => Carbon::now()]);
    }
}
