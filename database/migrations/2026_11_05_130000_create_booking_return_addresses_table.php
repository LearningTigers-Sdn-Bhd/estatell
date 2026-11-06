<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API only mode: the addresses a guest may return to after Curlec, the address each payment named, and one active API
 * only client per property. See docs/29-booking-engine/09-api-only-mode.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_return_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->timestamps();

            $table->unique(['property_id', 'url']);
        });

        DB::statement("ALTER TABLE booking_return_addresses ADD CONSTRAINT booking_return_addresses_https_check CHECK (url LIKE 'https://%')");

        Schema::table('gateway_payments', function (Blueprint $table) {
            $table->string('return_url', 500)->nullable()->after('status');
        });

        DB::statement("CREATE UNIQUE INDEX booking_clients_one_api_only ON booking_clients (property_id) WHERE type = 'api-only'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS booking_clients_one_api_only');

        Schema::table('gateway_payments', function (Blueprint $table) {
            $table->dropColumn('return_url');
        });

        Schema::dropIfExists('booking_return_addresses');
    }
};
