<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('migration backfills service_class from existing priority values 1:1', function () {
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
