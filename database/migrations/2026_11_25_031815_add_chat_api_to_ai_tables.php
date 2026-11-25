<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The chat API for n8n: one connection per n8n workflow, owning its `ai:chat` token, and the columns that find a chat API
 * thread again from the channel's own chat id. See docs/35-ai-assistant/03-places-and-routes.md#chat-api-for-n8n.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('property_id');
        });

        Schema::table('ai_threads', function (Blueprint $table) {
            $table->foreignId('ai_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_ref_hash', 64)->nullable();
            $table->string('channel', 20)->nullable();
            $table->string('guest_name', 100)->nullable();

            $table->index(['property_id', 'external_ref_hash']);
        });

        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->foreignId('ai_connection_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE api_idempotency_keys DROP CONSTRAINT api_idempotency_keys_one_owner_check');
        DB::statement('ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_one_owner_check CHECK (num_nonnulls(kiosk_id, booking_client_id, pos_api_client_id, ai_connection_id) = 1)');
    }

    public function down(): void
    {
        DB::table('api_idempotency_keys')->whereNotNull('ai_connection_id')->delete();
        DB::statement('ALTER TABLE api_idempotency_keys DROP CONSTRAINT api_idempotency_keys_one_owner_check');
        DB::statement('ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_one_owner_check CHECK (num_nonnulls(kiosk_id, booking_client_id, pos_api_client_id) = 1)');

        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_connection_id');
        });

        Schema::table('ai_threads', function (Blueprint $table) {
            $table->dropIndex(['property_id', 'external_ref_hash']);
            $table->dropConstrainedForeignId('ai_connection_id');
            $table->dropColumn(['external_ref_hash', 'channel', 'guest_name']);
        });

        Schema::dropIfExists('ai_connections');
    }
};
