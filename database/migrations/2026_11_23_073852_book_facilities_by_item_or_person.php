<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facilities are booked by item or by person, like rooms, instead of Private / Shared with a pax / units unit. A Private
 * facility becomes one item; a Shared one counted in units stays items; a Shared one counted in pax becomes person.
 * The Person rate basis becomes Each. See docs/33-facilities/02-facility-settings.md#booked-by-decided-2026-11-23.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table): void {
            $table->string('booked_by', 20)->nullable();
        });

        DB::statement("UPDATE facilities SET booked_by = 'item', capacity = 1 WHERE sharing = 'private'");
        DB::statement("UPDATE facilities SET booked_by = 'item' WHERE sharing = 'shared' AND capacity_unit = 'units'");
        DB::statement("UPDATE facilities SET booked_by = 'person' WHERE sharing = 'shared' AND capacity_unit = 'pax'");

        DB::statement('ALTER TABLE facilities DROP CONSTRAINT facilities_rate_basis_check');
        DB::statement("UPDATE facilities SET rate_basis = 'each' WHERE rate_basis = 'person'");
        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_rate_basis_check CHECK (rate_basis IN ('day', 'session', 'hour', 'each'))");

        DB::statement('ALTER TABLE facilities ALTER COLUMN booked_by SET NOT NULL');
        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_booked_by_check CHECK (booked_by IN ('item', 'person'))");

        Schema::table('facilities', function (Blueprint $table): void {
            $table->dropColumn(['sharing', 'capacity_unit']);
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table): void {
            $table->string('sharing', 20)->nullable();
            $table->string('capacity_unit', 20)->nullable();
        });

        DB::statement("UPDATE facilities SET sharing = CASE WHEN booked_by = 'item' AND capacity = 1 THEN 'private' ELSE 'shared' END, capacity_unit = CASE WHEN booked_by = 'person' THEN 'pax' ELSE 'units' END");

        DB::statement('ALTER TABLE facilities DROP CONSTRAINT facilities_rate_basis_check');
        DB::statement("UPDATE facilities SET rate_basis = 'person' WHERE rate_basis = 'each'");
        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_rate_basis_check CHECK (rate_basis IN ('day', 'session', 'hour', 'person'))");

        DB::statement('ALTER TABLE facilities ALTER COLUMN sharing SET NOT NULL');
        DB::statement('ALTER TABLE facilities ALTER COLUMN capacity_unit SET NOT NULL');
        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_sharing_check CHECK (sharing IN ('private', 'shared'))");
        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_capacity_unit_check CHECK (capacity_unit IN ('pax', 'units'))");

        Schema::table('facilities', function (Blueprint $table): void {
            $table->dropColumn('booked_by');
        });
    }
};
