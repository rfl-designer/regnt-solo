<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The baseline cuts behind the SLE (issue #145).
 *
 * A cut is a deliberate "stop counting the past" — the board changed in a
 * way that makes the older cycle times a different board's history (a new
 * way of working, a client that left, a method adopted), so the SLE is
 * recomputed from the cut forward. Every cut carries a mandatory motivo,
 * because a cut with no reason is indistinguishable from someone deleting
 * the numbers they didn't like.
 *
 * The migration seeds the first cut itself: adopting the Fluxo Solo *is*
 * the archetypal reason to cut, and the history that predates it was
 * produced by a board with different columns and no pull queue. Without
 * this row the first SLE would average two incompatible methods together
 * and quietly under-promise.
 *
 * `cut_at` is separate from `created_at` on purpose: a cut can be recorded
 * after the fact for a change that happened earlier, and the metric reads
 * `cut_at`.
 */
return new class extends Migration
{
    private const ADOPTION_REASON = 'adoção do Fluxo Solo';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('baseline_cuts', function (Blueprint $table): void {
            $table->id();
            $table->text('reason');
            $table->timestamp('cut_at')->index();
            $table->timestamps();
        });

        DB::table('baseline_cuts')->insert([
            'reason' => self::ADOPTION_REASON,
            'cut_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('baseline_cuts');
    }
};
