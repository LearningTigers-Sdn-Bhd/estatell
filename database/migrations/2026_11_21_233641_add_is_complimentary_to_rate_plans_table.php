<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A complimentary plan gives the room free (house use, staff, partners). Its nights count as sold for occupancy and
 * as Complimentary in History & forecast, and stay out of ADR there. See docs/22-reports/04-history-and-forecast.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->boolean('is_complimentary')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('is_complimentary');
        });
    }
};
