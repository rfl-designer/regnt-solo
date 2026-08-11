<?php

namespace App\Models;

use App\Enums\PolicyKey;
use App\Models\Builders\PolicyVersionBuilder;
use Database\Factories\PolicyVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;

/**
 * One written version of one board policy (issue #154).
 *
 * Append-only **by refusal**, not by convention: {@see record()} is the
 * only supported way to change a policy and it always inserts, and every
 * other route out — saving a loaded row, deleting it, or a mass
 * update/delete through the query builder — throws. The value of this
 * table is that the previous text is still readable; a single forgotten
 * `update()` anywhere would quietly destroy exactly that.
 *
 * The refusal covers Eloquent, which is how the application writes. A raw
 * `DB::table('policy_versions')` still bypasses it — nothing short of a
 * database grant closes that, and no code here takes that route.
 *
 * The version in force is the last row for the key ({@see current()}).
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
     * Refuse the writes that would rewrite history, at the one point every
     * loaded-model route passes through.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('O histórico de políticas é append-only: use PolicyVersion::record() para escrever uma versão nova.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('O histórico de políticas é append-only: uma versão registrada não é apagada.');
        });
    }

    /**
     * @param  QueryBuilder  $query
     * @return PolicyVersionBuilder<self>
     */
    public function newEloquentBuilder($query): PolicyVersionBuilder
    {
        return new PolicyVersionBuilder($query);
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
