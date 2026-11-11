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
            $table->unsignedSmallInteger('min_rooms')->nullable()->after('company_market');
            $table->unsignedSmallInteger('max_rooms')->nullable()->after('min_rooms');
        });

        DB::statement('ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_room_limits_check CHECK ((min_rooms IS NULL OR min_rooms BETWEEN 1 AND 200) AND (max_rooms IS NULL OR max_rooms BETWEEN 1 AND 200) AND (min_rooms IS NULL OR max_rooms IS NULL OR min_rooms <= max_rooms))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_room_limits_check');

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['min_rooms', 'max_rooms']);
        });
    }
};
