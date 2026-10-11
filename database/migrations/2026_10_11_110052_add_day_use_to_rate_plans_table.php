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
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('day_use_min_hours')->nullable();
            $table->unsignedSmallInteger('day_use_max_hours')->nullable();
            $table->time('day_use_from')->nullable();
            $table->time('day_use_until')->nullable();
        });

        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_pricing_basis_check');
        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_pricing_basis_check CHECK (pricing_basis IN ('per_room', 'per_guest', 'per_bed', 'package', 'day_use'))");
        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_day_use_check CHECK (
            (pricing_basis = 'day_use'
                AND parent_rate_plan_id IS NULL
                AND day_use_min_hours IS NOT NULL AND day_use_max_hours IS NOT NULL
                AND day_use_min_hours BETWEEN 1 AND 24 AND day_use_max_hours BETWEEN 1 AND 24
                AND day_use_min_hours <= day_use_max_hours
                AND ((day_use_from IS NULL AND day_use_until IS NULL)
                    OR (day_use_from IS NOT NULL AND day_use_until IS NOT NULL AND day_use_from < day_use_until)))
            OR (pricing_basis <> 'day_use'
                AND day_use_min_hours IS NULL AND day_use_max_hours IS NULL
                AND day_use_from IS NULL AND day_use_until IS NULL)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_day_use_check');
        DB::table('rate_plans')->where('pricing_basis', 'day_use')->delete();
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_pricing_basis_check');
        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_pricing_basis_check CHECK (pricing_basis IN ('per_room', 'per_guest', 'per_bed', 'package'))");

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['day_use_min_hours', 'day_use_max_hours', 'day_use_from', 'day_use_until']);
        });
    }
};
