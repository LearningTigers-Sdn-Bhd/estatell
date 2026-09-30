<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->string('weekend_nights', 20)->default('fri_sat');
        });

        DB::statement("ALTER TABLE property_settings ADD CONSTRAINT property_settings_weekend_nights_check CHECK (weekend_nights IN ('fri_sat', 'sat_sun'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('weekend_nights');
        });
    }
};
