<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservation_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->date('arrival_date');
            $table->date('departure_date');
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            $table->string('status', 20)->default('confirmed');
            $table->foreignId('discount_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('late_arrival_expected')->default(false);
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'room_type_id', 'status']);
            $table->index(['property_id', 'arrival_date']);
            $table->index('reservation_id');
            $table->index('rate_plan_id');
            $table->index('discount_id');
        });

        DB::statement("ALTER TABLE reservation_units ADD CONSTRAINT reservation_units_status_check CHECK (status IN ('confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'))");
        DB::statement('ALTER TABLE reservation_units ADD CONSTRAINT reservation_units_dates_check CHECK (departure_date > arrival_date)');
        DB::statement('ALTER TABLE reservation_units ADD CONSTRAINT reservation_units_adults_check CHECK (adults >= 1)');
        DB::statement('ALTER TABLE reservation_units ADD CONSTRAINT reservation_units_checked_out_check CHECK (checked_out_at IS NULL OR checked_in_at IS NOT NULL)');
        DB::statement("ALTER TABLE reservation_units ADD CONSTRAINT reservation_units_stay_timestamps_check CHECK (
            (status = 'checked_in' AND checked_in_at IS NOT NULL AND checked_out_at IS NULL)
            OR (status = 'checked_out' AND checked_in_at IS NOT NULL AND checked_out_at IS NOT NULL)
            OR (status IN ('confirmed', 'cancelled', 'no_show') AND checked_out_at IS NULL)
        )");

        Schema::create('unit_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('extra_amount', 12, 2)->default(0);
            $table->decimal('supplement_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);

            $table->unique(['reservation_unit_id', 'date']);
            $table->index('date');
            $table->index('rate_plan_id');
            $table->index('room_id');
            $table->index('bed_id');
        });

        DB::statement('ALTER TABLE unit_nights ADD CONSTRAINT unit_nights_amounts_check CHECK (base_amount >= 0 AND extra_amount >= 0 AND supplement_amount >= 0 AND discount_amount >= 0)');
        DB::statement('ALTER TABLE unit_nights ADD CONSTRAINT unit_nights_amount_total_check CHECK (amount = base_amount + extra_amount + supplement_amount - discount_amount)');
        DB::statement('ALTER TABLE unit_nights ADD CONSTRAINT unit_nights_room_or_bed_check CHECK (room_id IS NULL OR bed_id IS NULL)');

        Schema::create('unit_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);

            $table->unique(['reservation_unit_id', 'guest_id']);
            $table->index('guest_id');
        });

        DB::statement('CREATE UNIQUE INDEX unit_guests_one_primary_per_unit ON unit_guests (reservation_unit_id) WHERE is_primary');

        Schema::create('reservation_unit_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('age');

            $table->index('reservation_unit_id');
        });

        DB::statement('ALTER TABLE reservation_unit_children ADD CONSTRAINT reservation_unit_children_age_check CHECK (age >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservation_unit_children');
        Schema::dropIfExists('unit_guests');
        Schema::dropIfExists('unit_nights');
        Schema::dropIfExists('reservation_units');
    }
};
