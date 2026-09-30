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
        Schema::create('rate_plan_stay_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('min_nights');
            $table->string('discount_type', 10);
            $table->decimal('value', 12, 2);
            $table->unsignedSmallInteger('from_night')->default(1);
            $table->timestamps();

            $table->unique(['rate_plan_id', 'min_nights']);
        });

        DB::statement('ALTER TABLE rate_plan_stay_discounts ADD CONSTRAINT rate_plan_stay_discounts_nights_check CHECK (min_nights >= 2 AND from_night >= 1 AND from_night <= min_nights)');
        DB::statement("ALTER TABLE rate_plan_stay_discounts ADD CONSTRAINT rate_plan_stay_discounts_value_check CHECK (
            (discount_type = 'percent' AND value > 0 AND value <= 100)
            OR (discount_type = 'amount' AND value > 0)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_plan_stay_discounts');
    }
};
