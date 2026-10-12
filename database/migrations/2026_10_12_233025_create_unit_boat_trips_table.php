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
        Schema::create('unit_boat_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('mode', 10);
            $table->foreignId('boat_departure_id')->nullable()->constrained('boat_departures')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['reservation_unit_id', 'direction']);
        });

        DB::statement("ALTER TABLE unit_boat_trips ADD CONSTRAINT unit_boat_trips_direction_check CHECK (direction IN ('in', 'out'))");
        DB::statement("ALTER TABLE unit_boat_trips ADD CONSTRAINT unit_boat_trips_mode_check CHECK (mode IN ('scheduled', 'charter', 'own'))");
        DB::statement("ALTER TABLE unit_boat_trips ADD CONSTRAINT unit_boat_trips_departure_check CHECK ((mode = 'scheduled') = (boat_departure_id IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_boat_trips');
    }
};
