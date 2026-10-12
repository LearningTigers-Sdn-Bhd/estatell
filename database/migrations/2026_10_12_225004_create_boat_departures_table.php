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
        Schema::create('boat_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('direction', 10);
            $table->time('leaves_at', 0);
            $table->time('arrives_at', 0);
            $table->string('pickup_point', 150)->nullable();
            $table->integer('seats')->nullable();
            $table->foreignId('charge_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'direction', 'leaves_at']);
        });

        DB::statement("ALTER TABLE boat_departures ADD CONSTRAINT boat_departures_direction_check CHECK (direction IN ('in', 'out'))");
        DB::statement('ALTER TABLE boat_departures ADD CONSTRAINT boat_departures_time_order_check CHECK (arrives_at > leaves_at)');
        DB::statement('ALTER TABLE boat_departures ADD CONSTRAINT boat_departures_seats_check CHECK (seats IS NULL OR seats > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boat_departures');
    }
};
