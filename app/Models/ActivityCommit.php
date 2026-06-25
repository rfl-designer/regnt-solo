<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityCommit extends Model
{
    /** @use HasFactory<\Database\Factories\ActivityCommitFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'activity_id',
        'hash',
        'message',
        'files_changed',
        'insertions',
        'deletions',
        'committed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'files_changed' => 'integer',
            'insertions' => 'integer',
            'deletions' => 'integer',
        ];
    }

    /**
     * Get the activity this commit belongs to.
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * Scope to filter commits by activity.
     */
    public function scopeForActivity(Builder $query, int $activityId): void
    {
        $query->where('activity_id', $activityId);
    }

    /**
     * Scope to get recent commits.
     */
    public function scopeRecent(Builder $query, int $limit = 10): void
    {
        $query->orderByDesc('committed_at')->limit($limit);
    }
}
