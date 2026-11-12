<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('event_id');
            $table->jsonb('payload');
            $table->boolean('is_test')->default(false);
            $table->string('status');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_endpoint_id', 'event_id']);
            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('pending', 'retrying', 'delivered', 'refused', 'failed'))");
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_check CHECK (event IN ('booking.created', 'booking.updated', 'booking.cancelled', 'booking.no_show', 'stay.checked_in', 'stay.checked_out', 'payment.received', 'payment.refunded', 'folio.closed', 'guest.created', 'guest.updated', 'guest.merged', 'guest.anonymized', 'room.cleaned', 'room.maintenance_flagged', 'night_audit.completed', 'channel.alert'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
