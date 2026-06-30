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
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropUnique('tasks_github_issue_number_unique');
            $table->unique(['project_id', 'github_issue_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'github_issue_number']);
            $table->unique('github_issue_number', 'tasks_github_issue_number_unique');
        });
    }
};
