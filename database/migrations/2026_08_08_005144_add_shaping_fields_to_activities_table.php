<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three columns shaping needs that the board did not already have
 * (issue #148).
 *
 * Dor and Esboço are deliberately absent: they are `description` and `spec`,
 * the columns the Ideia and the Épico already carry. Shaping is a promotion
 * *in place* — the same row becomes the Épico — so giving Dor a column of its
 * own would mean the promoted Épico had its problem statement in one field
 * and every other surface reading another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            // Dias corridos, never hours: the appetite is a budget, and a
            // budget with false precision stops being a budget.
            $table->unsignedSmallInteger('appetite_days')->nullable()->after('spec');
            $table->text('rabbit_holes')->nullable()->after('appetite_days');
            $table->text('no_gos')->nullable()->after('rabbit_holes');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropColumn(['appetite_days', 'rabbit_holes', 'no_gos']);
        });
    }
};
