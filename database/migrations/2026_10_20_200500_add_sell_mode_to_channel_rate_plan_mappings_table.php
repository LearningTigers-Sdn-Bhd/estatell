<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Plans synced so far were sent per person when they were per-guest plans or per-guest
     * packages, and per room otherwise, so the column starts with what was sent.
     */
    public function up(): void
    {
        Schema::table('channel_rate_plan_mappings', function (Blueprint $table) {
            $table->string('sell_mode', 12)->default('per_room')->after('external_rate_plan_id');
        });

        DB::statement("UPDATE channel_rate_plan_mappings SET sell_mode = 'per_person' WHERE rate_plan_id IN (SELECT id FROM rate_plans WHERE pricing_basis = 'per_guest' OR (pricing_basis = 'package' AND package_unit = 'per_guest'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_rate_plan_mappings', function (Blueprint $table) {
            $table->dropColumn('sell_mode');
        });
    }
};
