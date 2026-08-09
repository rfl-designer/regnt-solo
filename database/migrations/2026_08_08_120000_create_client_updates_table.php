<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O update semanal por cliente, persistido (issue #149).
 *
 * Uma linha por update. `sent_at` nulo é o rascunho da semana — é ele que a
 * página edita, e é a ausência dele que faz o relógio da cadência correr.
 * Preenchê-lo é o ato de "marcar como enviado": fecha a janela, zera o
 * relógio e a linha vira histórico ("o que eu te disse semana passada?").
 *
 * `generated_content` guarda o texto exatamente como o gerador o montou.
 * É o que permite responder "houve edição manual?" sem adivinhar: o
 * conteúdo do quadro muda o tempo todo, então comparar o rascunho com uma
 * nova geração acusaria edição onde só houve movimento no board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->text('generated_content')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // As duas perguntas que o serviço faz: "qual é o rascunho deste
            // cliente?" e "quando foi o último envio?".
            $table->index(['client_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_updates');
    }
};
