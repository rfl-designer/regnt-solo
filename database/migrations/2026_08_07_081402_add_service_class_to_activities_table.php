<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds `service_class` and migrates existing `priority` values 1:1:
     * urgent -> emergency, high -> standard, medium -> standard, low -> intangible.
     * Nothing migrates to fixed_date — that classification always requires an
     * explicit due date (enforced separately at the Eloquent seam).
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->string('service_class')->default('standard')->after('priority');
        });

        $map = [
            'urgent' => 'emergency',
            'high' => 'standard',
            'medium' => 'standard',
            'low' => 'intangible',
        ];

        foreach ($map as $priority => $serviceClass) {
            DB::table('activities')
                ->where('priority', $priority)
                ->update(['service_class' => $serviceClass]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropColumn('service_class');
        });
    }
};
