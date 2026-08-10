<?php

namespace App\Models;

use App\Enums\PolicyKey;
use Database\Factories\PolicyVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One written version of one board policy (issue #154).
 *
 * Append-only by construction: {@see record()} is the only supported way
 * to change a policy and it always inserts. The version in force is the
 * last row for the key ({@see current()}), so the history is complete by
 * default rather than by anyone remembering to keep it.
 */
class PolicyVersion extends Model
{
    /** @use HasFactory<PolicyVersionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'body',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => PolicyKey::class,
        ];
    }

    /**
     * Rows of one policy, newest first.
     *
     * Ordered by `id` rather than `created_at` because two versions saved
     * in the same second — a typo fixed right after a save — still have an
     * unambiguous order, and "vigente" must never be a coin toss.
     */
    public function scopeForKey(Builder $query, PolicyKey $key): void
    {
        $query->where('key', $key)->orderByDesc('id');
    }

    /**
     * The version in force for a policy, or null while nothing was ever
     * written for it.
     */
    public static function current(PolicyKey $key): ?self
    {
        return static::query()->forKey($key)->first();
    }

    /**
     * The whole history of a policy, newest first.
     *
     * @return Collection<int, self>
     */
    public static function history(PolicyKey $key, ?int $limit = null): Collection
    {
        return static::query()->forKey($key)->when($limit !== null, fn ($query) => $query->limit($limit))->get();
    }

    /**
     * Write a new version. Never an update — that is the whole model.
     */
    public static function record(PolicyKey $key, string $body, ?string $note = null): self
    {
        $note = $note === null ? null : trim($note);

        return static::query()->create([
            'key' => $key,
            'body' => $body,
            'note' => $note === '' ? null : $note,
        ]);
    }
}
