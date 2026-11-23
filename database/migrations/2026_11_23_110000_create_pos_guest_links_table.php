<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_guest_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_connection_id')->constrained('pos_connections')->cascadeOnDelete();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->string('external_customer_id');
            $table->string('synced_label');
            $table->timestamp('last_synced_at');
            $table->timestamps();

            $table->unique(['pos_connection_id', 'reservation_unit_id']);
            $table->unique(['pos_connection_id', 'external_customer_id']);
        });

        Schema::table('pos_connections', function (Blueprint $table) {
            $table->string('external_webhook_id')->nullable()->after('external_merchant_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_connections', function (Blueprint $table) {
            $table->dropColumn('external_webhook_id');
        });

        Schema::dropIfExists('pos_guest_links');
    }
};
