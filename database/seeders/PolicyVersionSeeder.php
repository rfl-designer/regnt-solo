<?php

namespace Database\Seeders;

use App\Enums\PolicyKey;
use App\Models\PolicyVersion;
use Illuminate\Database\Seeder;

/**
 * The v1 of each written policy (issue #154).
 *
 * Runs in every environment, production included: an empty policy panel
 * is not a neutral starting point, it is a board with no written method.
 * The seeded texts are the method's defaults and are meant to be edited —
 * which is why they are written as a version like any other, carrying the
 * note that says where they came from.
 *
 * Idempotent by key: a policy that already has any version is left alone,
 * because re-seeding must never bury a text the user wrote under the
 * default again.
 */
class PolicyVersionSeeder extends Seeder
{
    public const SEED_NOTE = 'Padrão inicial do método';

    public function run(): void
    {
        foreach (PolicyKey::cases() as $key) {
            if (PolicyVersion::current($key) !== null) {
                continue;
            }

            PolicyVersion::record($key, $key->seedBody(), self::SEED_NOTE);
        }
    }
}
