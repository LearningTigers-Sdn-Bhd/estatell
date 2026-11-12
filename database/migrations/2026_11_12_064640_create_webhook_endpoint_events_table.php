<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoint_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event');

            $table->unique(['webhook_endpoint_id', 'event']);
            $table->index('event');
        });

        DB::statement("ALTER TABLE webhook_endpoint_events ADD CONSTRAINT webhook_endpoint_events_event_check CHECK (event IN ('booking.created', 'booking.updated', 'booking.cancelled', 'booking.no_show', 'stay.checked_in', 'stay.checked_out', 'payment.received', 'payment.refunded', 'folio.closed', 'guest.created', 'guest.updated', 'guest.merged', 'guest.anonymized', 'room.cleaned', 'room.maintenance_flagged', 'night_audit.completed', 'channel.alert'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoint_events');
    }
};
