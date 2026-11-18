<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a property try the Concierge page's online payment with Curlec's test cards. Off for every property, so stay
 * payments stay Live until someone turns it on. See docs/30-concierge/01-overview.md#payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->boolean('concierge_test_payments_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('concierge_test_payments_enabled');
        });
    }
};
