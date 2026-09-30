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
        Schema::create('rate_plan_child_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_age_group_id')->constrained()->cascadeOnDelete();
            $table->string('pricing', 10);
            $table->decimal('value', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['rate_plan_id', 'property_age_group_id']);
            $table->index('property_age_group_id');
        });

        DB::statement("ALTER TABLE rate_plan_child_prices ADD CONSTRAINT rate_plan_child_prices_pricing_check CHECK (
            (pricing = 'free' AND value IS NULL)
            OR (pricing = 'percent' AND value > 0 AND value <= 100)
            OR (pricing = 'fixed' AND value >= 0)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_plan_child_prices');
    }
};
