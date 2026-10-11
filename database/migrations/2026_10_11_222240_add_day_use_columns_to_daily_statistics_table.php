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
        Schema::table('daily_statistics', function (Blueprint $table) {
            $table->unsignedInteger('day_use_sold')->default(0)->after('in_house_guests');
            $table->decimal('day_use_revenue', 12, 2)->default(0)->after('day_use_sold');
        });

        DB::statement('ALTER TABLE daily_statistics ADD CONSTRAINT daily_statistics_day_use_check CHECK (day_use_revenue >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE daily_statistics DROP CONSTRAINT daily_statistics_day_use_check');

        Schema::table('daily_statistics', function (Blueprint $table) {
            $table->dropColumn(['day_use_sold', 'day_use_revenue']);
        });
    }
};
