<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The OTA collected method starts active, so channel bookings the OTA collected can post their payment, and every
     * existing property is switched on. See docs/21-channels/05-booking-ingestion.md.
     */
    public function up(): void
    {
        DB::table('payment_methods')->where('type', 'ota_collected')->where('active', false)->update(['active' => true, 'updated_at' => now()]);
    }

    /**
     * Nothing to undo: which properties were off before is not kept.
     */
    public function down(): void {}
};
