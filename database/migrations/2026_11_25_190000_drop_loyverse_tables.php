<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_postings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_connection_id');
        });

        Schema::dropIfExists('pos_guest_links');
        Schema::dropIfExists('pos_outlet_mappings');
        Schema::dropIfExists('pos_connections');
    }

    public function down(): void
    {
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
            $table->string('external_webhook_id')->nullable();
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

        Schema::create('pos_guest_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections')->cascadeOnDelete();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->string('external_customer_id');
            $table->string('synced_label');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['pos_connection_id', 'reservation_unit_id']);
        });

        Schema::table('pos_postings', function (Blueprint $table) {
            $table->foreignId('pos_connection_id')->nullable()->after('pos_outlet_id')->constrained('pos_connections')->nullOnDelete();
        });
    }
};
