<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MorningRitualFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The record of a day's morning ritual (issue #147).
 *
 * One row per day, and it holds two facts: when the ritual was concluded
 * and whatever the user wrote down while doing it. Deliberately *not* a
 * list of what was decided — the board is the list, and the ritual's whole
 * point is to work the board rather than copy it somewhere else.
 *
 * `completed_at` is written once. Reopening the wizard later in the same
 * day is a normal thing to do (checking the queue again, pulling one more
 * item), and it must not move the timestamp: "a primeira conclusão do dia
 * vale" is what makes the badge and the MCP status answer a stable
 * question. {@see complete()} enforces that.
 */
class MorningRitual extends Model
{
    /** @use HasFactory<MorningRitualFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * `completed_at` is deliberately absent: it may only be stamped by
     * {@see complete()}, so no caller can forge (or move) the moment the
     * day's ritual was concluded.
     *
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get (or create) the ritual record for a given date.
     */
    public static function getOrCreateForDate(CarbonInterface $date): static
    {
        $dateString = $date->toDateString();

        return static::query()->whereDate('date', $dateString)->first()
            ?? static::create(['date' => $dateString]);
    }

    /**
     * Today's ritual record, or null when the day hasn't produced one yet.
     */
    public static function today(): ?static
    {
        return static::query()->whereDate('date', today()->toDateString())->first();
    }

    /**
     * Whether today's ritual has already been concluded — the single
     * question the sidebar badge and the `get-ritual-status` MCP tool ask.
     */
    public static function completedToday(): bool
    {
        return static::today()?->isCompleted() ?? false;
    }

    /**
     * Whether this day's ritual was concluded.
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Conclude the ritual, keeping the first conclusion of the day.
     *
     * Notes are always saved (a second pass may add to them); the timestamp
     * is only stamped once.
     */
    public function complete(?string $notes = null): static
    {
        $notes = $notes === null ? null : trim($notes);

        $this->notes = $notes === '' ? null : $notes;

        if ($this->completed_at === null) {
            $this->completed_at = now();
        }

        $this->save();

        return $this;
    }

    /**
     * The "já concluído às HH:MM" rendering, shared by every surface that
     * shows it so they cannot disagree on the format.
     */
    public function completedAtLabel(): ?string
    {
        return $this->completed_at?->format('H:i');
    }
}
