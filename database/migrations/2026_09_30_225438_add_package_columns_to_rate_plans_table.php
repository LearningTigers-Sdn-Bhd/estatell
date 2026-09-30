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
            $table->unsignedSmallInteger('fixed_nights')->nullable()->after('pricing_basis');
            $table->string('package_unit', 20)->nullable()->after('fixed_nights');
        });

        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_package_check CHECK (
            (pricing_basis = 'package' AND fixed_nights IS NOT NULL AND fixed_nights BETWEEN 1 AND 30 AND package_unit IS NOT NULL AND package_unit IN ('per_guest', 'per_room'))
            OR (pricing_basis <> 'package' AND fixed_nights IS NULL AND package_unit IS NULL)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_package_check');

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['fixed_nights', 'package_unit']);
        });
    }
};
