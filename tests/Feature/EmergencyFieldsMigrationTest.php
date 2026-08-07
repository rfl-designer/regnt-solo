<?php

use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The migration is not zero-touch by design: `service_class = emergency`
 * came from a bulk mapping of `priority = urgent`, so the database can
 * hold many active emergencies and nothing in it says which one is real.
 * These tests pin the reconciliation it performs — and, above all, that it
 * refuses to guess a survivor.
 */
$migrationPath = 'migrations/2026_08_07_120000_add_emergency_fields_to_activities_table.php';

/**
 * Build a legacy row the way the previous migration left it: classified
 * emergency, with no motivo and no `emergency_since`. Saved quietly so the
 * observer's guard doesn't refuse the very state this migration exists to
 * clean up.
 */
function legacyEmergency(string $title, bool $done = false): Activity
{
    $task = $done
        ? Activity::factory()->issue()->done()->create(['title' => $title])
        : Activity::factory()->issue()->todo()->create(['title' => $title]);

    $task->forceFill([
        'service_class' => 'emergency',
        'emergency_reason' => null,
        'emergency_since' => null,
    ])->saveQuietly();

    return $task;
}

test('every existing emergency is backfilled with a motivo so the observer can save it', function () use ($migrationPath) {
    $done = legacyEmergency('Emergência já concluída', done: true);

    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();

    $row = DB::table('activities')->where('id', $done->id)->first();

    expect($row->service_class)->toBe('emergency')
        ->and($row->emergency_reason)->not->toBeNull();
});

test('emergency_since is left null for legacy rows rather than faking the age from created_at', function () use ($migrationPath) {
    $done = legacyEmergency('Emergência antiga', done: true);
    DB::table('activities')->where('id', $done->id)->update(['created_at' => now()->subYear()]);

    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();

    // The item's birthday is not the day it was classified, and nothing
    // recorded the latter — "desconhecido" is the honest answer.
    expect(DB::table('activities')->where('id', $done->id)->value('emergency_since'))->toBeNull()
        ->and($done->fresh()->emergencyDays())->toBe(0);
});

test('every active legacy emergency is demoted — the migration crowns no arbitrary survivor', function () use ($migrationPath) {
    $ids = collect(range(1, 3))->map(fn (int $i): int => legacyEmergency("Legado {$i}")->id);

    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();

    $stillEmergency = DB::table('activities')
        ->whereIn('id', $ids)
        ->where('service_class', 'emergency')
        ->pluck('id');

    expect($stillEmergency)->toBeEmpty()
        ->and(DB::table('activities')->whereIn('id', $ids)->count())->toBe(3);
});

test('the demotions are logged with ids and titles, since down() cannot restore them', function () use ($migrationPath) {
    $task = legacyEmergency('Legado que perde a classe');

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return str_contains($message, 'issue #143')
                && $context['activities'] === [$task->id => 'Legado que perde a classe'];
        });

    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();
});

test('a done emergency is never demoted — it is history, not board state', function () use ($migrationPath) {
    $done = legacyEmergency('Resolvido', done: true);

    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();

    expect(DB::table('activities')->where('id', $done->id)->value('service_class'))->toBe('emergency');
});

test('down() only removes the emergency columns, preserving rows', function () use ($migrationPath) {
    Activity::factory()->count(3)->create();
    $countBefore = DB::table('activities')->count();

    $migration = require database_path($migrationPath);
    $migration->down();

    expect(Schema::hasColumn('activities', 'emergency_reason'))->toBeFalse()
        ->and(Schema::hasColumn('activities', 'emergency_since'))->toBeFalse()
        ->and(DB::table('activities')->count())->toBe($countBefore);

    // Restore the columns so the rest of the suite (which runs in the same
    // migrated schema) isn't affected by this test's teardown.
    $migration->up();

    expect(Schema::hasColumn('activities', 'emergency_reason'))->toBeTrue()
        ->and(Schema::hasColumn('activities', 'emergency_since'))->toBeTrue();
});
