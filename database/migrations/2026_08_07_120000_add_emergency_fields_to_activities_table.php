<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the two fields behind the single-Emergency invariant (issue #143):
 *
 * - `emergency_reason`: the mandatory "por que isso é uma Emergência?"
 *   collected when an activity is classified as Emergência. Cleared by
 *   ActivityObserver whenever the activity is reclassified to another
 *   service class, kept when it is concluded.
 * - `emergency_since`: when the activity *entered* the Emergência class,
 *   which is what "idade da Emergência ativa" is measured from. Mirrors
 *   `waiting_since` from issue #142: stamped only by the observer, never
 *   fillable, so nothing can forge it.
 *
 * Data reconciliation: the invariant ("no máximo uma Emergência ativa")
 * cannot be true for pre-existing rows, because
 * `2026_08_07_081402_add_service_class_to_activities_table` mapped *every*
 * urgent activity to `emergency`. This migration therefore:
 *
 * 1. backfills a placeholder reason + `emergency_since` for every existing
 *    Emergência, so no legacy row is left in a state the observer refuses
 *    to save; and
 * 2. demotes every *active* (not-done, on-board) Emergência except the most
 *    recently created one back to `standard`, so exactly one survives.
 *
 * Done Emergências are left untouched — they are history, not board state,
 * and the invariant only covers the board. `down()` simply drops the two
 * columns; the demotions are not reversible (the original class was
 * `emergency` for all of them, which is exactly the state that made the
 * invariant unenforceable).
 */
return new class extends Migration
{
    private const BACKFILL_REASON = 'Classificada como Emergência antes do registro de motivo (migrada).';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->text('emergency_reason')->nullable()->after('waiting_since');
            $table->timestamp('emergency_since')->nullable()->after('emergency_reason');
        });

        DB::table('activities')
            ->where('service_class', 'emergency')
            ->update([
                'emergency_reason' => self::BACKFILL_REASON,
                'emergency_since' => DB::raw('created_at'),
            ]);

        $survivorId = DB::table('activities')
            ->where('service_class', 'emergency')
            ->whereNotNull('status')
            ->where('status', '!=', 'done')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        DB::table('activities')
            ->where('service_class', 'emergency')
            ->whereNotNull('status')
            ->where('status', '!=', 'done')
            ->when($survivorId !== null, fn ($query) => $query->where('id', '!=', $survivorId))
            ->update([
                'service_class' => 'standard',
                'emergency_reason' => null,
                'emergency_since' => null,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropColumn(['emergency_reason', 'emergency_since']);
        });
    }
};
