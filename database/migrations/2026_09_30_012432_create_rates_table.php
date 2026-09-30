<?php

use App\Actions\Staff\SyncPermissions;
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
        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('min_stay')->nullable();
            $table->unsignedSmallInteger('max_stay')->nullable();
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
            $table->boolean('stop_sell')->default(false);
            $table->timestamps();

            $table->unique(['room_type_id', 'rate_plan_id', 'date']);
            $table->index(['rate_plan_id', 'date']);
        });

        DB::statement('ALTER TABLE rates ADD CONSTRAINT rates_amount_check CHECK (amount IS NULL OR amount >= 0)');
        DB::statement('ALTER TABLE rates ADD CONSTRAINT rates_stay_check CHECK ((min_stay IS NULL OR min_stay >= 1) AND (max_stay IS NULL OR max_stay >= 1) AND (min_stay IS NULL OR max_stay IS NULL OR max_stay >= min_stay))');

        Schema::create('rate_occupancy_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('adults');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['rate_id', 'adults']);
        });

        DB::statement('ALTER TABLE rate_occupancy_prices ADD CONSTRAINT rate_occupancy_prices_check CHECK (adults >= 1 AND amount >= 0)');

        app(SyncPermissions::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_occupancy_prices');
        Schema::dropIfExists('rates');
    }
};
