<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Um rascunho aberto por cliente, garantido pelo banco (issue #149).
 *
 * O serviço já reaproveita o rascunho existente em vez de abrir um segundo,
 * mas "consultar e então criar" não é atômico: a página e um agente por MCP
 * podem passar os dois pelo "não há rascunho" e criar um cada. Com dois
 * abertos, enviar um deixa o outro na fila — e enviá-lo depois zeraria o
 * relógio da cadência com o texto de uma janela antiga.
 *
 * A trava fica no banco porque é lá que a corrida acontece. Um índice único
 * sobre `sent_at IS NULL` não serve: em SQLite e MySQL dois NULLs são
 * distintos, então a restrição não restringiria nada. Daí a coluna
 * `draft_client_id`, derivada e nunca preenchida à mão: ela vale `client_id`
 * enquanto a linha é rascunho e NULL depois do envio, o que faz o índice
 * único valer exatamente sobre os rascunhos e liberar o histórico inteiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_updates', function (Blueprint $table) {
            $table->foreignId('draft_client_id')->nullable()->after('client_id')->constrained('clients')->cascadeOnDelete();
        });

        // Os rascunhos que já existiam nascem com a marca. Se algum cliente
        // tiver dois — a corrida que esta migração fecha —, o índice abaixo
        // falha aqui, alto e claro, em vez de a migração escolher sozinha
        // qual dos dois textos jogar fora.
        DB::table('client_updates')
            ->whereNull('sent_at')
            ->update(['draft_client_id' => DB::raw('client_id')]);

        Schema::table('client_updates', function (Blueprint $table) {
            $table->unique('draft_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_updates', function (Blueprint $table) {
            $table->dropUnique(['draft_client_id']);
            $table->dropConstrainedForeignId('draft_client_id');
        });
    }
};
