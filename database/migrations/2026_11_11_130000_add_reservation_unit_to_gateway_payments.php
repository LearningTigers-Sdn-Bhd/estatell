<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Curlec order is for a booking hold (the booking engine) or for a stay (the Concierge page pays what is due before
 * check-in). Exactly one of the two is set, so refunds and the webhook treat every order the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_payments', function (Blueprint $table) {
            $table->foreignId('reservation_unit_id')->nullable()->after('booking_hold_id')->constrained()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE gateway_payments ALTER COLUMN booking_hold_id DROP NOT NULL');
        DB::statement('ALTER TABLE gateway_payments ADD CONSTRAINT gateway_payments_one_target_check CHECK ((booking_hold_id IS NOT NULL) <> (reservation_unit_id IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gateway_payments DROP CONSTRAINT gateway_payments_one_target_check');
        DB::table('gateway_payments')->whereNull('booking_hold_id')->delete();
        DB::statement('ALTER TABLE gateway_payments ALTER COLUMN booking_hold_id SET NOT NULL');

        Schema::table('gateway_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_unit_id');
        });
    }
};
