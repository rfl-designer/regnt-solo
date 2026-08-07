<?php

use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migration is not zero-touch by design: the invariant "no máximo uma
 * Emergência ativa" cannot hold for rows produced by the priority ->
 * service_class mapping, which turned every urgent activity into an
 * emergency. These tests pin the reconciliation it performs.
 */
$migrationPath = 'migrations/2026_08_07_120000_add_emergency_fields_to_activities_table.php';

test('every existing emergency is backfilled with a motivo and an emergency_since', function () use ($migrationPath) {
    $emergency = Activity::factory()->issue()->done()->emergency('Motivo original')->create();

    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();

    $row = DB::table('activities')->where('id', $emergency->id)->first();

    expect($row->service_class)->toBe('emergency')
        ->and($row->emergency_reason)->not->toBeNull()
        ->and($row->emergency_since)->not->toBeNull();
});

test('surplus active emergencies are demoted to standard so exactly one survives', function () use ($migrationPath) {
    // Built with saveQuietly so the observer's guard doesn't refuse the
    // very state this migration exists to clean up.
    $ids = collect(range(1, 3))->map(function (int $i): int {
        $task = Activity::factory()->issue()->todo()->create(['title' => "Legado {$i}"]);
        $task->forceFill(['service_class' => 'emergency', 'emergency_reason' => null])->saveQuietly();

        return $task->id;
    });

    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();

    $stillEmergency = DB::table('activities')
        ->whereIn('id', $ids)
        ->where('service_class', 'emergency')
        ->pluck('id');

    expect($stillEmergency)->toHaveCount(1)
        ->and($stillEmergency->first())->toBe($ids->last());

    // The demoted ones keep everything except the classification.
    expect(DB::table('activities')->whereIn('id', $ids)->count())->toBe(3);
});

test('a done emergency is never demoted — it is history, not board state', function () use ($migrationPath) {
    $done = Activity::factory()->issue()->done()->emergency('Resolvido')->create();
    $active = Activity::factory()->issue()->todo()->emergency('Ativo')->create();

    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();

    expect(DB::table('activities')->where('id', $done->id)->value('service_class'))->toBe('emergency')
        ->and(DB::table('activities')->where('id', $active->id)->value('service_class'))->toBe('emergency');
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
