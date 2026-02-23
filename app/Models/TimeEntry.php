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
        'feature_id',
        'task_id',
        'started_at',
        'stopped_at',
        'notes',
        'is_focus_session',
        'focus_rating',
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
            'is_focus_session' => 'boolean',
            'focus_rating' => 'integer',
        ];
    }

    /**
     * Get the feature this time entry belongs to.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    /**
     * Get the task this time entry belongs to.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the duration in minutes.
     */
    protected function durationMinutes(): Attribute
    {
        return Attribute::get(function (): float {
            $end = $this->stopped_at ?? Carbon::now();

            return round($this->started_at->diffInMinutes($end), 2);
        });
    }

    /**
     * Scope to only running time entries (no stopped_at).
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereNull('stopped_at');
    }

    /**
     * Scope to time entries for a specific date.
     */
    public function scopeForDate(Builder $query, Carbon $date): void
    {
        $query->whereDate('started_at', $date);
    }

    /**
     * Scope to time entries for the current week.
     */
    public function scopeForWeek(Builder $query): void
    {
        $query->whereBetween('started_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek(),
        ]);
    }

    /**
     * Scope to only focus sessions.
     */
    public function scopeFocusSessions(Builder $query): void
    {
        $query->where('is_focus_session', true);
    }

    /**
     * Scope to focus sessions for a specific date.
     */
    public function scopeFocusForDate(Builder $query, Carbon $date): void
    {
        $query->focusSessions()->whereDate('started_at', $date);
    }

    /**
     * Scope to focus sessions for the current week.
     */
    public function scopeFocusForWeek(Builder $query): void
    {
        $query->focusSessions()->whereBetween('started_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek(),
        ]);
    }

    /**
     * Get the total focus duration in minutes for a given date (or all time).
     */
    public static function focusDurationMinutes(?Carbon $date = null): float
    {
        $query = static::query()
            ->focusSessions()
            ->whereNotNull('stopped_at');

        if ($date !== null) {
            $query->whereDate('started_at', $date);
        }

        return $query->get()->sum('duration_minutes');
    }

    /**
     * Stop all currently running time entries.
     */
    public static function stopAllRunning(): int
    {
        return static::query()
            ->running()
            ->update(['stopped_at' => now()]);
    }
}
