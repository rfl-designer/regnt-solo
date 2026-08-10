<?php

namespace App\Models\Builders;

use App\Models\PolicyVersion;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * The query builder of {@see PolicyVersion} (issue #154).
 *
 * The model refuses to update or delete a row it holds; this refuses the
 * mass operations, which never load a model and so would slip past the
 * event guards. Together they make append-only a property of the code
 * instead of a convention someone has to remember.
 *
 * @template TModel of \App\Models\PolicyVersion
 *
 * @extends Builder<TModel>
 */
class PolicyVersionBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw new RuntimeException('O histórico de políticas é append-only: use PolicyVersion::record() para escrever uma versão nova.');
    }

    public function delete(): mixed
    {
        throw new RuntimeException('O histórico de políticas é append-only: uma versão registrada não é apagada.');
    }

    public function forceDelete(): mixed
    {
        throw new RuntimeException('O histórico de políticas é append-only: uma versão registrada não é apagada.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new RuntimeException('O histórico de políticas é append-only: use PolicyVersion::record() para escrever uma versão nova.');
    }
}
