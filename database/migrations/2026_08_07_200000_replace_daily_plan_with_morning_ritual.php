<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The morning ritual replaces the Daily Planner (issue #147).
 *
 * The record survives the page: a day still has one row with notes, so the
 * table is reused rather than dropped and recreated. What changes is what a
 * row *means*. A daily plan was a list of items picked for the day — hence
 * the pivot. The ritual is an event: it happened, at a time, and left
 * notes. There is no list, because the board already holds the work and
 * duplicating it into a second place is exactly the double source of truth
 * the Fluxo Solo is removing.
 *
 * So: the pivot is dropped (the list is gone, not merely unused), the table
 * is renamed to what it now records, and `completed_at` is added — the
 * timestamp that answers "já fiz o ritual hoje?", which is the only
 * question the sidebar badge and the MCP tool ask.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('daily_plan_activity');

        Schema::rename('daily_plans', 'morning_rituals');

        Schema::table('morning_rituals', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('morning_rituals', function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });

        Schema::rename('morning_rituals', 'daily_plans');

        Schema::create('daily_plan_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['daily_plan_id', 'activity_id']);
        });
    }
};
