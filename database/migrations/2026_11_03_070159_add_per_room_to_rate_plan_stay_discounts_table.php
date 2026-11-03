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
        Schema::table('rate_plan_stay_discounts', function (Blueprint $table) {
            $table->boolean('per_room')->default(false)->after('value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rate_plan_stay_discounts', function (Blueprint $table) {
            $table->dropColumn('per_room');
        });
    }
};
