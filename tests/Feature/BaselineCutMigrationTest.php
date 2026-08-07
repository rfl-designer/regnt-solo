<?php

use App\Models\BaselineCut;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The baseline starts cut (issue #145). Adopting the Fluxo Solo changed
 * the columns, the pull order and what "Pronto" means — measuring an SLE
 * across that boundary would average two different boards together, so the
 * migration records the cut itself rather than waiting for someone to
 * remember.
 */
$migrationPath = 'migrations/2026_08_07_183600_create_baseline_cuts_table.php';

test('the migration seeds the adoption cut with its motivo', function () use ($migrationPath) {
    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();

    $cuts = DB::table('baseline_cuts')->get();

    expect($cuts)->toHaveCount(1)
        ->and($cuts->first()->reason)->toBe('adoção do Fluxo Solo')
        ->and($cuts->first()->cut_at)->not->toBeNull();
});

test('the seeded cut is the one the metrics read as the baseline start', function () use ($migrationPath) {
    $migration = require database_path($migrationPath);
    $migration->down();
    $migration->up();

    expect(BaselineCut::query()->latestFirst()->first()->reason)->toBe('adoção do Fluxo Solo');
});

test('down() drops the table', function () use ($migrationPath) {
    $migration = require database_path($migrationPath);
    $migration->down();

    expect(Schema::hasTable('baseline_cuts'))->toBeFalse();

    // Restore it so the rest of the suite runs against the migrated schema.
    $migration->up();

    expect(Schema::hasTable('baseline_cuts'))->toBeTrue();
});
