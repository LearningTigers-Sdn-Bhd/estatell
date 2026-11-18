<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payment mode of the webhook route that received each Curlec event. An event only acts on payments of the same
 * mode, so the test secret, which more machines hold, can never confirm a live order. Existing events are Live.
 * See docs/29-booking-engine/04-payment.md#payment-mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_events', function (Blueprint $table) {
            $table->string('mode')->default('live');
        });

        DB::statement("ALTER TABLE gateway_events ADD CONSTRAINT gateway_events_mode_check CHECK (mode IN ('test', 'live'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gateway_events DROP CONSTRAINT gateway_events_mode_check');

        Schema::table('gateway_events', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
