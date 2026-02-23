<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('feature_id')
                ->nullable()
                ->after('id')
                ->index()
                ->constrained()
                ->nullOnDelete();

            $table->dropForeign(['task_id']);
            $table->foreignId('task_id')
                ->nullable()
                ->change();
            $table->foreign('task_id')
                ->references('id')
                ->on('tasks')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feature_id');

            $table->dropForeign(['task_id']);
            $table->foreignId('task_id')
                ->nullable(false)
                ->change();
            $table->foreign('task_id')
                ->references('id')
                ->on('tasks')
                ->cascadeOnDelete();
        });
    }
};
