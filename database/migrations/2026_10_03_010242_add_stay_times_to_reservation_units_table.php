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
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->time('arrival_time')->nullable()->after('departure_date');
            $table->time('departure_time')->nullable()->after('arrival_time');
        });

        DB::statement('UPDATE reservation_units SET arrival_time = property_settings.check_in_time, departure_time = property_settings.check_out_time FROM property_settings WHERE property_settings.property_id = reservation_units.property_id');
        DB::statement("UPDATE reservation_units SET arrival_time = COALESCE(arrival_time, '15:00'), departure_time = COALESCE(departure_time, '11:00')");

        Schema::table('reservation_units', function (Blueprint $table) {
            $table->time('arrival_time')->nullable(false)->change();
            $table->time('departure_time')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->dropColumn(['arrival_time', 'departure_time']);
        });
    }
};
