<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Stakeholder extends Model
{
    /** @use HasFactory<\Database\Factories\StakeholderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'client_id',
        'name',
        'email',
        'access_token',
        'last_accessed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_accessed_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Stakeholder $stakeholder) {
            if (! $stakeholder->access_token) {
                $stakeholder->access_token = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the project that owns this stakeholder.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the client this stakeholder is linked to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the issues opened by this stakeholder.
     */
    public function stakeholderIssues(): HasMany
    {
        return $this->hasMany(StakeholderIssue::class);
    }

    /**
     * Get the public URL for this stakeholder.
     */
    public function getPublicUrlAttribute(): string
    {
        return url('/projects/shared/'.$this->access_token);
    }
}
