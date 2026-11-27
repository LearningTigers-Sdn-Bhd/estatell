<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The number of bags of a road transfer, for the driver's manifest. See docs/35-transfers/01-land-transfers.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_transfers', function (Blueprint $table) {
            $table->unsignedSmallInteger('bags')->nullable()->after('flight_number');
        });
        DB::statement('ALTER TABLE unit_transfers ADD CONSTRAINT unit_transfers_bags_check CHECK (bags IS NULL OR bags BETWEEN 0 AND 99)');
    }

    public function down(): void
    {
        Schema::table('unit_transfers', function (Blueprint $table) {
            $table->dropColumn('bags');
        });
    }
};
