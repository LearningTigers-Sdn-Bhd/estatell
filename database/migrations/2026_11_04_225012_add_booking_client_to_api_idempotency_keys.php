<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stored answer now belongs to a kiosk or a booking client. A booking client may have no property in the path, so the
 * property becomes optional. See docs/28-api-access/03-kiosk-check-in-endpoints.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->foreignId('booking_client_id')->nullable()->after('kiosk_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('property_id')->nullable()->change();
            $table->unsignedBigInteger('kiosk_id')->nullable()->change();
            $table->unique(['booking_client_id', 'key', 'path']);
        });

        DB::statement('ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_one_owner_check CHECK (num_nonnulls(kiosk_id, booking_client_id) = 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE api_idempotency_keys DROP CONSTRAINT api_idempotency_keys_one_owner_check');
        DB::table('api_idempotency_keys')->whereNull('kiosk_id')->delete();

        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->dropUnique(['booking_client_id', 'key', 'path']);
            $table->dropConstrainedForeignId('booking_client_id');
            $table->unsignedBigInteger('property_id')->nullable(false)->change();
            $table->unsignedBigInteger('kiosk_id')->nullable(false)->change();
        });
    }
};
