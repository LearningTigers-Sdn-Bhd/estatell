<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A FIT rate can name the group rate that larger bookings use. The booking form and the portal switch a booking's
     * rooms to it once the booking has more rooms than the FIT rate allows, and back when it shrinks.
     */
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->foreignId('group_rate_plan_id')->nullable()->after('max_rooms')->constrained('rate_plans')->nullOnDelete();
        });

        DB::statement('ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_group_rate_plan_check CHECK (group_rate_plan_id IS NULL OR (group_rate_plan_id <> id AND max_rooms IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_group_rate_plan_check');

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_rate_plan_id');
        });
    }
};
