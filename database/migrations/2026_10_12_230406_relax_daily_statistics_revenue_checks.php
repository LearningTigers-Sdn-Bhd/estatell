<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations. A reversal reduces the date it is posted on, so a date can net below zero. ADR, RevPAR and
     * day use revenue keep that figure; only occupancy stays non-negative.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE daily_statistics DROP CONSTRAINT daily_statistics_money_check');
        DB::statement('ALTER TABLE daily_statistics ADD CONSTRAINT daily_statistics_occupancy_check CHECK (occupancy >= 0)');
        DB::statement('ALTER TABLE daily_statistics DROP CONSTRAINT daily_statistics_day_use_check');
    }

    /**
     * Reverse the migrations. Fails while a row holds a negative figure.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE daily_statistics ADD CONSTRAINT daily_statistics_day_use_check CHECK (day_use_revenue >= 0)');
        DB::statement('ALTER TABLE daily_statistics DROP CONSTRAINT daily_statistics_occupancy_check');
        DB::statement('ALTER TABLE daily_statistics ADD CONSTRAINT daily_statistics_money_check CHECK (occupancy >= 0 AND adr >= 0 AND revpar >= 0)');
    }
};
