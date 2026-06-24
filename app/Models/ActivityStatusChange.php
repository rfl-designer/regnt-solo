<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityStatusChange extends Model
{
    /** @use HasFactory<\Database\Factories\ActivityStatusChangeFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'activity_id',
        'from_status',
        'to_status',
        'changed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ActivityStatus::class,
            'to_status' => ActivityStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    /**
     * Get the activity this status change belongs to.
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * Scope to filter status changes for a specific activity.
     */
    public function scopeForActivity(Builder $query, int $activityId): void
    {
        $query->where('activity_id', $activityId);
    }

    /**
     * Scope to filter status changes for a specific status (as to_status).
     */
    public function scopeForStatus(Builder $query, ActivityStatus $status): void
    {
        $query->where('to_status', $status);
    }
}
