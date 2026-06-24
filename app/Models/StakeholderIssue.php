<?php

namespace App\Models;

use App\Enums\StakeholderIssueStatus;
use App\Observers\StakeholderIssueRealtimeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([StakeholderIssueRealtimeObserver::class])]
class StakeholderIssue extends Model
{
    /** @use HasFactory<\Database\Factories\StakeholderIssueFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'stakeholder_id',
        'project_id',
        'activity_id',
        'comment',
        'status',
        'converted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StakeholderIssueStatus::class,
            'converted_at' => 'datetime',
        ];
    }

    /**
     * Get the stakeholder that owns this issue.
     */
    public function stakeholder(): BelongsTo
    {
        return $this->belongsTo(Stakeholder::class);
    }

    /**
     * Get the project that owns this issue.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the activity linked to this issue.
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
