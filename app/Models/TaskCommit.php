<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCommit extends Model
{
    /** @use HasFactory<\Database\Factories\TaskCommitFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
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
     * Get the task this commit belongs to.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Scope to filter commits by task.
     */
    public function scopeForTask(Builder $query, int $taskId): void
    {
        $query->where('task_id', $taskId);
    }

    /**
     * Scope to get recent commits.
     */
    public function scopeRecent(Builder $query, int $limit = 10): void
    {
        $query->orderByDesc('committed_at')->limit($limit);
    }
}
