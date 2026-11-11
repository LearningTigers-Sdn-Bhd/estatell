<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A room kept for a guest while they pay. See docs/29-booking-engine/03-checkout-and-hold.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_client_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('status');
            $table->date('arrival_date');
            $table->date('departure_date');
            $table->decimal('total_amount', 12, 2);
            $table->timestamp('expires_at');
            $table->text('guest_name')->nullable();
            $table->text('guest_email')->nullable();
            $table->text('guest_phone')->nullable();
            $table->text('guest_nationality')->nullable();
            $table->text('special_requests')->nullable();
            $table->string('guest_ip_hash', 64)->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        DB::statement('ALTER TABLE booking_holds ADD CONSTRAINT booking_holds_dates_check CHECK (departure_date > arrival_date)');
        DB::statement("CREATE INDEX booking_holds_open_index ON booking_holds (property_id, arrival_date, departure_date) WHERE status IN ('held', 'paid')");

        Schema::create('booking_hold_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_hold_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('adults');
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index('room_type_id');
        });

        Schema::create('booking_hold_line_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_hold_line_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('age');

            $table->index('booking_hold_line_id');
        });

        DB::statement('ALTER TABLE booking_hold_line_children ADD CONSTRAINT booking_hold_line_children_age_check CHECK (age >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_hold_line_children');
        Schema::dropIfExists('booking_hold_lines');
        Schema::dropIfExists('booking_holds');
    }
};
