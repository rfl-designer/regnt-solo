<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O hill da spec (issue #149): binário, manual e nullable.
 *
 * Binário porque um slider de 0–100% é precisão teatral — ninguém sabe que
 * uma spec está "63% pronta", e comunicar isso ao cliente é inventar um
 * número que depois vira cobrança. "Em descoberta" e "em execução" são as
 * duas coisas que o dev de fato sabe.
 *
 * Nullable porque não marcar é um estado legítimo: o gerador do update
 * simplesmente omite a frase quando não há posição declarada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('hill_position')->nullable()->after('no_gos');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('hill_position');
        });
    }
};
