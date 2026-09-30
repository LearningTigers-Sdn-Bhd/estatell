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
        Schema::create('package_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->date('arrival_from');
            $table->date('arrival_to');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['rate_plan_id', 'room_type_id', 'arrival_from']);
        });

        DB::statement('ALTER TABLE package_prices ADD CONSTRAINT package_prices_check CHECK (arrival_from <= arrival_to AND amount >= 0)');

        Schema::create('rate_plan_arrival_weekdays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->string('weekday', 10);
            $table->timestamps();

            $table->unique(['rate_plan_id', 'weekday']);
        });

        DB::statement("ALTER TABLE rate_plan_arrival_weekdays ADD CONSTRAINT rate_plan_arrival_weekdays_check CHECK (weekday IN ('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_plan_arrival_weekdays');
        Schema::dropIfExists('package_prices');
    }
};
