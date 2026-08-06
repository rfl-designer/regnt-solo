<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `client_id` to `activities` and enforces, at the database level, that
 * an activity's project link and direct client link never coexist.
 *
 * The `ActivityObserver` already clears the direct client whenever a project
 * is set, but that only protects writes that go through Eloquent events.
 * Writes via `Activity::query()->update()`, `saveQuietly()`, raw SQL, or
 * concurrent updates bypass the observer entirely, so a real CHECK
 * constraint is added here as the source of truth for the invariant.
 *
 * Driver note: MySQL (8.0.16+) and PostgreSQL support `ADD CONSTRAINT ...
 * CHECK` directly via `ALTER TABLE`. SQLite does not allow adding a CHECK
 * constraint that references more than the newly added column via
 * `ALTER TABLE ... ADD COLUMN`, and has no `ALTER TABLE ... ADD CONSTRAINT`
 * at all — the only way to add a multi-column CHECK to an existing SQLite
 * table is to rebuild it. This migration rebuilds the table by reading its
 * current `CREATE TABLE` / index SQL from `sqlite_master`, splicing the
 * CHECK clause into the table definition, and recreating it with the data
 * copied over — so it never needs to hardcode the activities schema and
 * stays correct as columns are added over time.
 */
return new class extends Migration
{
    private const CONSTRAINT_NAME = 'chk_activities_project_or_client';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });

        $this->addCheckConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropCheckConstraint();

        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }

    private function addCheckConstraint(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => $this->rebuildSqliteTable(
                fn (string $createSql) => preg_replace(
                    '/\)(\s*)$/',
                    ', CONSTRAINT '.self::CONSTRAINT_NAME.' CHECK (project_id IS NULL OR client_id IS NULL))$1',
                    $createSql,
                    1
                )
            ),
            'pgsql' => DB::statement(
                'ALTER TABLE activities ADD CONSTRAINT '.self::CONSTRAINT_NAME.' CHECK (project_id IS NULL OR client_id IS NULL)'
            ),
            default => DB::statement(
                'ALTER TABLE activities ADD CONSTRAINT '.self::CONSTRAINT_NAME.' CHECK (project_id IS NULL OR client_id IS NULL)'
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
