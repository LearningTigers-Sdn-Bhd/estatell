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
        Schema::create('rate_plan_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('meal_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('charging_unit', 30);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('allocated_amount', 12, 2);
            $table->string('starts_on', 30);
            $table->string('ends_on', 30);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['rate_plan_id', 'position']);
        });

        DB::statement("ALTER TABLE rate_plan_inclusions ADD CONSTRAINT rate_plan_inclusions_charging_unit_check CHECK (charging_unit IN ('per_item', 'per_night', 'per_stay', 'per_guest_night', 'per_guest'))");
        DB::statement("ALTER TABLE rate_plan_inclusions ADD CONSTRAINT rate_plan_inclusions_starts_on_check CHECK (starts_on IN ('arrival_day', 'day_after_arrival'))");
        DB::statement("ALTER TABLE rate_plan_inclusions ADD CONSTRAINT rate_plan_inclusions_ends_on_check CHECK (ends_on IN ('departure_day', 'night_before_departure'))");
        DB::statement('ALTER TABLE rate_plan_inclusions ADD CONSTRAINT rate_plan_inclusions_amount_check CHECK (quantity >= 1 AND allocated_amount >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_plan_inclusions');
    }
};
