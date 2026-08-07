<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforces, at the database level, that an activity classified as "Data
 * fixa" (fixed_date) always has a due_date.
 *
 * `ActivityObserver::saving()` already refuses this combination for any
 * write that goes through an Eloquent model save. But `Activity::query()
 * ->update()`, `upsert()`, and raw `DB::table('activities')->update()`
 * bypass Eloquent events entirely, so a real CHECK constraint is added
 * here as the source of truth for the invariant — the same pattern used
 * by `2026_08_06_191812_add_client_id_to_activities_table` for the
 * project/client exclusivity rule.
 *
 * Driver note: MySQL (8.0.16+) and PostgreSQL support `ADD CONSTRAINT ...
 * CHECK` directly via `ALTER TABLE`. SQLite has no `ALTER TABLE ... ADD
 * CONSTRAINT` at all — the only way to add a CHECK to an existing SQLite
 * table is to rebuild it. This migration rebuilds the table by reading
 * its current `CREATE TABLE` / index SQL from `sqlite_master`, splicing
 * the CHECK clause into the table definition, and recreating it with the
 * data copied over — so it never needs to hardcode the activities schema
 * and stays correct as columns are added over time.
 */
return new class extends Migration
{
    private const CONSTRAINT_NAME = 'chk_activities_fixed_date_requires_due_date';

    private const CHECK_SQL = "service_class <> 'fixed_date' OR due_date IS NOT NULL";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->addCheckConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropCheckConstraint();
    }

    private function addCheckConstraint(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => $this->rebuildSqliteTable(
                fn (string $createSql) => preg_replace(
                    '/\)(\s*)$/',
                    ', CONSTRAINT '.self::CONSTRAINT_NAME.' CHECK ('.self::CHECK_SQL.'))$1',
                    $createSql,
                    1
                )
            ),
            'pgsql' => DB::statement(
                'ALTER TABLE activities ADD CONSTRAINT '.self::CONSTRAINT_NAME.' CHECK ('.self::CHECK_SQL.')'
            ),
            default => DB::statement(
                'ALTER TABLE activities ADD CONSTRAINT '.self::CONSTRAINT_NAME.' CHECK ('.self::CHECK_SQL.')'
            ),
        };
    }

    private function dropCheckConstraint(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => $this->rebuildSqliteTable(
                fn (string $createSql) => preg_replace(
                    '/,\s*CONSTRAINT '.self::CONSTRAINT_NAME.' CHECK \([^)]*\)/',
                    '',
                    $createSql,
                    1
                )
            ),
            'mysql' => DB::statement('ALTER TABLE activities DROP CHECK '.self::CONSTRAINT_NAME),
            default => DB::statement('ALTER TABLE activities DROP CONSTRAINT '.self::CONSTRAINT_NAME),
        };
    }

    /**
     * Rebuild the `activities` table in SQLite using its own current schema,
     * transformed by the given callback, and copy all data over.
     *
     * @param  callable(string): string  $transformCreateSql
     */
    private function rebuildSqliteTable(callable $transformCreateSql): void
    {
        $createSql = DB::selectOne(
            "select sql from sqlite_master where type = 'table' and name = 'activities'"
        )->sql;

        $indexSqls = collect(DB::select(
            "select sql from sqlite_master where type = 'index' and tbl_name = 'activities' and sql is not null"
        ))->pluck('sql');

        $newCreateSql = $transformCreateSql($createSql);

        DB::statement('PRAGMA foreign_keys = OFF');
        // `legacy_alter_table` stops SQLite from rewriting other tables'
        // foreign key definitions to point at the temporary name below —
        // the table gets its original name back, so those references must
        // stay untouched.
        DB::statement('PRAGMA legacy_alter_table = ON');
        DB::statement('ALTER TABLE activities RENAME TO activities_rebuild_tmp');
        DB::statement($newCreateSql);
        DB::statement('INSERT INTO activities SELECT * FROM activities_rebuild_tmp');
        DB::statement('DROP TABLE activities_rebuild_tmp');
        DB::statement('PRAGMA legacy_alter_table = OFF');

        foreach ($indexSqls as $indexSql) {
            DB::statement($indexSql);
        }

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
