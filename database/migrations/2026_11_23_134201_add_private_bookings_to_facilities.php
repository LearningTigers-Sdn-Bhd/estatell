<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Private bookings of a Person facility (a charter): the facility's price for one private booking, and the flag on
     * the booking. See docs/33-facilities/02-facility-settings.md#private-bookings.
     */
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->decimal('private_amount', 12, 2)->nullable()->after('rate_basis');
        });

        Schema::table('facility_bookings', function (Blueprint $table) {
            $table->boolean('private')->default(false)->after('quantity');
        });

        DB::statement('ALTER TABLE facilities ADD CONSTRAINT facilities_private_amount_check CHECK (private_amount IS NULL OR private_amount >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE facilities DROP CONSTRAINT IF EXISTS facilities_private_amount_check');

        Schema::table('facility_bookings', function (Blueprint $table) {
            $table->dropColumn('private');
        });

        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('private_amount');
        });
    }
};
