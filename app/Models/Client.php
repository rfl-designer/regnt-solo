<?php

namespace App\Models;

use App\Enums\ClientChannel;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'color',
        'update_day',
        'update_time',
        'channel',
        'response_agreement',
        'notes',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ClientChannel::class,
            'update_day' => 'integer',
            'update_time' => 'datetime:H:i',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the projects linked to this client.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get the activities directly linked to this client (no project).
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Get the stakeholders linked to this client.
     */
    public function stakeholders(): HasMany
    {
        return $this->hasMany(Stakeholder::class);
    }

    /**
     * Every weekly update written for this client — the draft in progress
     * and everything already sent (issue #149).
     */
    public function updates(): HasMany
    {
        return $this->hasMany(ClientUpdate::class);
    }

    /**
     * The two updates the queue actually needs: the last one sent and the
     * draft still open.
     *
     * They exist as relations so the queue can eager-load exactly these two
     * rows per client. Loading `updates` instead would hydrate every update
     * ever written — full markdown and all — on a page the sidebar badge
     * renders everywhere, and the cost would grow with the history forever
     * (issue #149).
     */
    public function latestSentUpdate(): HasOne
    {
        return $this->hasOne(ClientUpdate::class)->sent()->latestOfMany('sent_at');
    }

    public function currentDraft(): HasOne
    {
        return $this->hasOne(ClientUpdate::class)->draft()->latestOfMany();
    }

    /**
     * The last update the client actually received, or null while none has
     * been sent. This is the anchor of both the cadence clock and the
     * window the next draft covers.
     */
    public function lastSentUpdate(): ?ClientUpdate
    {
        return $this->updates()->sent()->orderByDesc('sent_at')->orderByDesc('id')->first();
    }

    /**
     * The draft currently being written, or null when there is none.
     *
     * There is at most one in practice — the page and the MCP tool both
     * reuse the open draft instead of opening a second — and the newest one
     * wins if a stale row ever survives.
     */
    public function draftUpdate(): ?ClientUpdate
    {
        return $this->updates()->draft()->orderByDesc('id')->first();
    }

    /**
     * Scope to only active (non-archived) clients.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to only archived clients.
     */
    public function scopeArchived(Builder $query): void
    {
        $query->where('is_active', false);
    }

    /**
     * Get the ISO weekday label for the update day (1 = Monday ... 7 = Sunday).
     */
    public function updateDayLabel(): string
    {
        return self::weekDayLabel($this->update_day);
    }

    /**
     * Get the PT-BR label for an ISO weekday (1 = Monday ... 7 = Sunday).
     *
     * Single source of truth for the weekday label, shared by the model
     * accessor and the client form/listing views.
     */
    public static function weekDayLabel(int $day): string
    {
        return match ($day) {
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
            default => 'Domingo',
        };
    }
}
