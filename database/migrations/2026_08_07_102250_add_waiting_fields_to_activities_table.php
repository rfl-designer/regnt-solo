<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the "esperando quem" (waiting_for) and "desde quando" (waiting_since)
 * fields used by the three waiting statuses introduced in issue #142
 * (awaiting_approval, waiting, awaiting_validation). Both are nullable and
 * default to null, so this migration is zero-touch: no existing row's
 * status changes, and every existing row simply gets null in both new
 * columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->string('waiting_for')->nullable()->after('service_class');
            $table->timestamp('waiting_since')->nullable()->after('waiting_for');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropColumn(['waiting_for', 'waiting_since']);
        });
    }
};
