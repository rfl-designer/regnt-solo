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
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            $table->longText('spec')->nullable();
            $table->string('priority')->default('medium')->index();
            $table->date('due_date')->nullable()->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
