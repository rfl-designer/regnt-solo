<?php

use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the migration is zero-touch: no existing activity changes status, and new columns default to null', function () {
    $tasks = Activity::factory()->count(4)->create();
    $statusById = $tasks->pluck('status', 'id');
    $countBefore = DB::table('activities')->count();

    $migration = require database_path('migrations/2026_08_07_102250_add_waiting_fields_to_activities_table.php');
    $migration->down();

    expect(Schema::hasColumn('activities', 'waiting_for'))->toBeFalse()
        ->and(Schema::hasColumn('activities', 'waiting_since'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('activities', 'waiting_for'))->toBeTrue()
        ->and(Schema::hasColumn('activities', 'waiting_since'))->toBeTrue()
        ->and(DB::table('activities')->count())->toBe($countBefore);

    foreach ($tasks as $task) {
        $row = DB::table('activities')->where('id', $task->id)->first();

        expect($row->status)->toBe($statusById[$task->id]->value)
            ->and($row->waiting_for)->toBeNull()
            ->and($row->waiting_since)->toBeNull();
    }
});

test('migration down() only removes the waiting columns, preserving rows', function () {
    $tasks = Activity::factory()->count(3)->create();
    $countBefore = DB::table('activities')->count();

    $migration = require database_path('migrations/2026_08_07_102250_add_waiting_fields_to_activities_table.php');
    $migration->down();

    expect(Schema::hasColumn('activities', 'waiting_for'))->toBeFalse()
        ->and(Schema::hasColumn('activities', 'waiting_since'))->toBeFalse()
        ->and(DB::table('activities')->count())->toBe($countBefore);

    // Restore the columns so the rest of the suite (which runs in the same
    // migrated schema) isn't affected by this test's teardown.
    $migration->up();
});
