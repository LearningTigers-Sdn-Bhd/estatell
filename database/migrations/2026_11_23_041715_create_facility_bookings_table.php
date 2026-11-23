<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One booking of one facility for a time, a quantity and a folio, owned by an event or a reservation.
     * See docs/33-facilities/04-facility-bookings.md.
     */
    public function up(): void
    {
        Schema::create('facility_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('folio_id')->constrained()->restrictOnDelete();
            $table->date('booking_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->integer('quantity');
            $table->decimal('unit_amount', 12, 2)->nullable();
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['facility_id', 'booking_date']);
            $table->index('event_id');
            $table->index('reservation_id');
        });

        DB::statement('ALTER TABLE facility_bookings ADD CONSTRAINT facility_bookings_owner_check CHECK ((event_id IS NULL) <> (reservation_id IS NULL))');
        DB::statement('ALTER TABLE facility_bookings ADD CONSTRAINT facility_bookings_times_check CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE facility_bookings ADD CONSTRAINT facility_bookings_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE facility_bookings ADD CONSTRAINT facility_bookings_unit_amount_check CHECK (unit_amount IS NULL OR unit_amount >= 0)');
        DB::statement("ALTER TABLE facility_bookings ADD CONSTRAINT facility_bookings_status_check CHECK (status IN ('tentative', 'confirmed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_bookings');
    }
};
