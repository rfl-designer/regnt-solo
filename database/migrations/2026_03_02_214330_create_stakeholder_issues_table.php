<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stakeholder_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stakeholder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->text('comment');
            $table->string('status')->default('unread')->index();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['stakeholder_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stakeholder_issues');
    }
};
