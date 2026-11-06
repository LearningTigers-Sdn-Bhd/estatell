<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cancellation terms an online guest was promised, saved when the booking is confirmed.
 * See docs/29-booking-engine/05-cancel-and-refund.md#saved-terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_cancellation_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('charge_mode');
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('percent', 5, 2)->nullable();
            $table->string('percent_basis')->nullable();
            $table->string('applies_to');
            $table->unsignedSmallInteger('free_until_days')->nullable();
            $table->text('text_en');
            $table->text('text_ms');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE reservation_cancellation_terms ADD CONSTRAINT reservation_cancellation_terms_charge_mode_check CHECK (charge_mode IN ('free', 'fixed_amount', 'percentage'))");
        DB::statement("ALTER TABLE reservation_cancellation_terms ADD CONSTRAINT reservation_cancellation_terms_percent_basis_check CHECK (percent_basis IS NULL OR percent_basis IN ('booked_rate', 'current_rate'))");
        DB::statement("ALTER TABLE reservation_cancellation_terms ADD CONSTRAINT reservation_cancellation_terms_applies_to_check CHECK (applies_to IN ('first_night', 'whole_stay', 'unused_nights', 'one_charge'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_cancellation_terms');
    }
};
