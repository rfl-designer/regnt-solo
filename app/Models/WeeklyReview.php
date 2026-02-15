<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeeklyReview extends Model
{
    /** @use HasFactory<\Database\Factories\WeeklyReviewFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'week_start',
        'week_end',
        'notes',
        'reflection',
        'generated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Scope to find the review for the week containing the given date.
     */
    public function scopeForWeek(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('week_start', $date->copy()->startOfWeek());
    }

    /**
     * Get or create a WeeklyReview for the week containing the given date.
     */
    public static function getOrCreateForWeek(CarbonInterface $date): static
    {
        $weekStart = $date->copy()->startOfWeek();
        $weekEnd = $date->copy()->endOfWeek();

        return static::query()->whereDate('week_start', $weekStart)->first()
            ?? static::create([
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'generated_at' => now(),
            ]);
    }
}
