<?php

namespace App\Models;

use Database\Factories\BaselineCutFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A deliberate reset of the SLE's memory (issue #145).
 *
 * Everything concluded *after* the most recent cut is the baseline
 * population; everything before it is history that no longer describes how
 * this board works. The motivo is mandatory by construction — see
 * {@see FlowMetricsService::cut()} — so the audit trail always answers
 * "why did the numbers restart here?".
 */
class BaselineCut extends Model
{
    /** @use HasFactory<BaselineCutFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reason',
        'cut_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cut_at' => 'datetime',
        ];
    }

    /**
     * Newest cut first — the order the history is read and displayed in.
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('cut_at')->orderByDesc('id');
    }
}
