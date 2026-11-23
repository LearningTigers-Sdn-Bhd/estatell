<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 30);
            $table->foreignId('default_charge_item_id')->constrained('charge_items')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'code']);
        });

        Schema::create('pos_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('status', 30);
            $table->string('external_merchant_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'provider']);
        });

        Schema::create('pos_outlet_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections')->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->string('external_store_id');
            $table->string('external_payment_type_id');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['pos_connection_id', 'external_store_id']);
        });

        Schema::create('pos_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->foreignId('pos_connection_id')->nullable()->constrained('pos_connections')->nullOnDelete();
            $table->foreignId('reservation_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('folio_line_id')->nullable()->constrained('folio_lines')->nullOnDelete();
            $table->string('external_receipt_id');
            $table->string('external_receipt_number');
            $table->string('posting_type', 20);
            $table->string('status', 30);
            $table->decimal('amount', 12, 2);
            $table->string('failure_reason')->nullable();
            $table->jsonb('raw_payload');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['pos_outlet_id', 'external_receipt_id', 'posting_type']);
            $table->index(['property_id', 'status']);
        });

        Schema::create('pos_api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('property_id');
        });

        Schema::create('meal_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('meal_period_id')->constrained()->restrictOnDelete();
            $table->date('service_date');
            $table->unsignedSmallInteger('redeemed_guests');
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at');
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'service_date', 'meal_period_id']);
            $table->index(['reservation_unit_id', 'service_date', 'meal_period_id']);
        });

        DB::statement('ALTER TABLE pos_outlets ADD CONSTRAINT pos_outlets_code_upper_check CHECK (code = upper(code))');
        DB::statement('ALTER TABLE pos_postings ADD CONSTRAINT pos_postings_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE meal_redemptions ADD CONSTRAINT meal_redemptions_guests_check CHECK (redeemed_guests BETWEEN 1 AND 99)');

        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->foreignId('pos_api_client_id')->nullable()->constrained('pos_api_clients')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE api_idempotency_keys DROP CONSTRAINT api_idempotency_keys_one_owner_check');
        DB::statement('ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_one_owner_check CHECK (num_nonnulls(kiosk_id, booking_client_id, pos_api_client_id) = 1)');
    }

    public function down(): void
    {
        DB::table('api_idempotency_keys')->whereNotNull('pos_api_client_id')->delete();
        DB::statement('ALTER TABLE api_idempotency_keys DROP CONSTRAINT api_idempotency_keys_one_owner_check');
        DB::statement('ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_one_owner_check CHECK (num_nonnulls(kiosk_id, booking_client_id) = 1)');

        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_api_client_id');
        });

        Schema::dropIfExists('meal_redemptions');
        Schema::dropIfExists('pos_api_clients');
        Schema::dropIfExists('pos_postings');
        Schema::dropIfExists('pos_outlet_mappings');
        Schema::dropIfExists('pos_connections');
        Schema::dropIfExists('pos_outlets');
    }
};
