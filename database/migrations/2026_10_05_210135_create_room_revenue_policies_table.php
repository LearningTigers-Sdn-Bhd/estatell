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
        Schema::create('room_revenue_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('charge_mode')->default('free');
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('percent', 5, 2)->nullable();
            $table->string('percent_basis')->nullable();
            $table->string('applies_to');
            $table->unsignedSmallInteger('free_until_days')->nullable();
            $table->text('text_en');
            $table->text('text_ms');
            $table->timestamps();

            $table->unique(['property_id', 'code']);
        });

        DB::statement("ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_code_check CHECK (code IN ('no_show_revenue', 'cancellation_revenue', 'early_departure_revenue', 'late_checkout_revenue'))");
        DB::statement("ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_charge_mode_check CHECK (charge_mode IN ('free', 'fixed_amount', 'percentage'))");
        DB::statement("ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_percent_basis_check CHECK (percent_basis IS NULL OR percent_basis IN ('booked_rate', 'current_rate'))");
        DB::statement("ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_applies_to_check CHECK (applies_to IN ('first_night', 'whole_stay', 'unused_nights', 'one_charge'))");
        DB::statement('ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_amount_check CHECK (amount IS NULL OR amount >= 0)');
        DB::statement('ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_percent_check CHECK (percent IS NULL OR (percent >= 0 AND percent <= 100))');
        DB::statement("ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_mode_fields_check CHECK ((charge_mode = 'free' AND amount IS NULL AND percent IS NULL AND percent_basis IS NULL) OR (charge_mode = 'fixed_amount' AND amount IS NOT NULL AND percent IS NULL AND percent_basis IS NULL) OR (charge_mode = 'percentage' AND amount IS NULL AND percent IS NOT NULL AND percent_basis IS NOT NULL))");
        DB::statement("ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_free_until_days_check CHECK (free_until_days IS NULL OR code = 'cancellation_revenue')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_revenue_policies');
    }
};
