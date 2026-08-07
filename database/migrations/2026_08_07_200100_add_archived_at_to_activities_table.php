<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arquivar é um carimbo, não um status (issue #147).
 *
 * The first step of the morning ritual clears the Feito column, and the
 * obvious-looking implementation — an eighth column, or a status called
 * "arquivado" — would silently rewrite the board's history: every flow
 * metric reads the status history, so moving an item out of Feito would
 * reopen its cycle-time clock and change the SLE. A separate timestamp
 * cannot do that. The item stays Feito forever; `archived_at` only says
 * the user has already looked at it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('completed_at');
            $table->index('archived_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
