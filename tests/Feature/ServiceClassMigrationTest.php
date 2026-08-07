<?php

use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('migration backfills service_class from existing priority values 1:1', function () {
    // The `chk_activities_fixed_date_requires_due_date` CHECK constraint
    // (added by a later migration) references `service_class`, so SQLite
    // refuses to drop that column while the constraint exists. Drop the
    // constraint first to simulate the true pre-migration state.
    $checkMigration = require database_path('migrations/2026_08_07_092633_add_fixed_date_due_date_check_to_activities_table.php');
    $checkMigration->down();

    // Simulate the pre-migration state: drop the column the migration adds,
    // then seed legacy rows with only `priority` set, exactly like production
    // data before this migration ever ran.
    Schema::table('activities', function ($table): void {
        $table->dropColumn('service_class');
    });

    $ids = [];

    foreach (['urgent', 'high', 'medium', 'low'] as $priority) {
        $ids[$priority] = DB::table('activities')->insertGetId([
            'type' => 'task',
            'title' => "Legacy task ({$priority})",
            'status' => 'backlog',
            'priority' => $priority,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $migration = require database_path('migrations/2026_08_07_081402_add_service_class_to_activities_table.php');
    $migration->up();

    expect(DB::table('activities')->where('id', $ids['urgent'])->value('service_class'))->toBe('emergency')
        ->and(DB::table('activities')->where('id', $ids['high'])->value('service_class'))->toBe('standard')
        ->and(DB::table('activities')->where('id', $ids['medium'])->value('service_class'))->toBe('standard')
        ->and(DB::table('activities')->where('id', $ids['low'])->value('service_class'))->toBe('intangible');

    // Nothing migrates to fixed_date.
    expect(DB::table('activities')->where('service_class', 'fixed_date')->count())->toBe(0);
});

test('migration down() only removes service_class, preserving rows and original priority', function () {
    $tasks = Activity::factory()->count(3)->create();
    $priorityById = $tasks->pluck('priority', 'id');
    $countBefore = DB::table('activities')->count();

    // The CHECK constraint (added by a later migration) also references
    // `service_class`, so it must be dropped first — same as production
    // rollback order.
    $checkMigration = require database_path('migrations/2026_08_07_092633_add_fixed_date_due_date_check_to_activities_table.php');
    $checkMigration->down();

    $migration = require database_path('migrations/2026_08_07_081402_add_service_class_to_activities_table.php');
    $migration->down();

    expect(Schema::hasColumn('activities', 'service_class'))->toBeFalse()
        ->and(Schema::hasColumn('activities', 'priority'))->toBeTrue()
        ->and(DB::table('activities')->count())->toBe($countBefore);

    foreach ($tasks as $task) {
        expect(DB::table('activities')->where('id', $task->id)->value('priority'))
            ->toBe($priorityById[$task->id]->value);
    }
});
