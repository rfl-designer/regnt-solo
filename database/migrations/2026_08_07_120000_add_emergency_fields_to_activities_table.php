<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * ## Why the legacy data can't just be kept
 *
 * `2026_08_07_081402_add_service_class_to_activities_table` mapped *every*
 * `priority = urgent` activity to `service_class = emergency`. None of
 * those rows was ever declared an Emergência: the classification did not
 * exist yet, nobody wrote a motivo for them, and there is no timestamp
 * saying when any of them "became" one. So the database can hold many
 * active emergencies, and no column in it can say which one is real.
 *
 * This migration therefore refuses to guess. Rather than crowning an
 * arbitrary survivor by `created_at` — which records when the *item* was
 * born, not when it was classified — it demotes **every** active legacy
 * Emergência to `standard` and logs the ids it touched. The board comes
 * back with no Emergência lit, which is true: none had been declared. The
 * first real one is then classified deliberately, with a motivo, through
 * the normal flow.
 *
 * Concluded Emergências are left alone — they are history, not board
 * state, and the invariant only covers the board. They keep the class and
 * get a placeholder motivo so the observer can still save them; their
 * `emergency_since` stays null, which reads as "idade desconhecida"
 * instead of pretending the item's birthday was its classification.
 *
 * `down()` drops the two columns. It cannot restore the demotions —
 * `emergency` was the value for all of them, which is precisely the state
 * that made the invariant unenforceable — so the log line is the audit
 * trail for reconciling them by hand if ever needed.
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
            ->update(['emergency_reason' => self::BACKFILL_REASON]);

        $demoted = DB::table('activities')
            ->where('service_class', 'emergency')
            ->whereNotNull('status')
            ->where('status', '!=', 'done')
            ->pluck('title', 'id');

        if ($demoted->isEmpty()) {
            return;
        }

        DB::table('activities')
            ->whereIn('id', $demoted->keys())
            ->update([
                'service_class' => 'standard',
                'emergency_reason' => null,
                'emergency_since' => null,
            ]);

        Log::warning(
            'issue #143: demoted legacy active emergencies to standard — they came from the priority=urgent mapping and were never declared emergencies. Reclassify deliberately if any of them still is one.',
            ['activities' => $demoted->all()],
        );
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
