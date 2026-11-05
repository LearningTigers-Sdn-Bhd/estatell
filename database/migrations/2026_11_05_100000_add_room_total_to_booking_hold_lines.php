<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stay price before tax that the guest was shown, so a rate change cannot change a booking they paid for. It is empty
 * on a hold made before this. See docs/29-booking-engine/04-payment.md#price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_hold_lines', function (Blueprint $table) {
            $table->decimal('room_total', 12, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('booking_hold_lines', function (Blueprint $table) {
            $table->dropColumn('room_total');
        });
    }
};
