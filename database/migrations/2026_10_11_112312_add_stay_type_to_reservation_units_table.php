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
            $table->string('stay_type', 20)->default('overnight')->after('status');
        });

        DB::statement('ALTER TABLE reservation_units DROP CONSTRAINT reservation_units_dates_check');
        DB::statement("ALTER TABLE reservation_units ADD CONSTRAINT reservation_units_stay_type_check CHECK (stay_type IN ('overnight', 'day_use'))");
        DB::statement("ALTER TABLE reservation_units ADD CONSTRAINT reservation_units_dates_check CHECK (
            (stay_type = 'overnight' AND departure_date > arrival_date)
            OR (stay_type = 'day_use' AND departure_date = arrival_date)
        )");
    }

    /**
     * Reverse the migrations. Day use units are deleted first, because they break the old dates rule.
     */
    public function down(): void
    {
        DB::table('reservation_units')->where('stay_type', 'day_use')->delete();

        DB::statement('ALTER TABLE reservation_units DROP CONSTRAINT reservation_units_dates_check');
        DB::statement('ALTER TABLE reservation_units DROP CONSTRAINT reservation_units_stay_type_check');
        DB::statement('ALTER TABLE reservation_units ADD CONSTRAINT reservation_units_dates_check CHECK (departure_date > arrival_date)');

        Schema::table('reservation_units', function (Blueprint $table) {
            $table->dropColumn('stay_type');
        });
    }
};
