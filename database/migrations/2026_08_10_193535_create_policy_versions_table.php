<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only log of the board's written policies (issue #154).
 *
 * There is no `policies` table with one editable row per section, on
 * purpose: the point of writing a policy down is being able to read, six
 * weeks later, that the Definição de Feito changed on a Tuesday and why —
 * which is exactly the information an UPDATE destroys. So this is a log in
 * the spirit of `activity_status_changes`: the current policy is the last
 * row for its key, saving inserts, and nothing is ever overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('body');
            // "Por que mudou" — optional, because the first version has no
            // previous one to explain and a mandatory field would just be
            // filled with noise.
            $table->text('note')->nullable();
            $table->timestamps();

            // Every read is "the latest rows for this key", in both the
            // panel and the MCP tool.
            $table->index(['key', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
    }
};
