<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskStatusChange extends Model
{
    /** @use HasFactory<\Database\Factories\TaskStatusChangeFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
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
            'from_status' => TaskStatus::class,
            'to_status' => TaskStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    /**
     * Get the task this status change belongs to.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Scope to filter status changes for a specific task.
     */
    public function scopeForTask(Builder $query, int $taskId): void
    {
        $query->where('task_id', $taskId);
    }

    /**
     * Scope to filter status changes for a specific status (as to_status).
     */
    public function scopeForStatus(Builder $query, TaskStatus $status): void
    {
        $query->where('to_status', $status);
    }
}
