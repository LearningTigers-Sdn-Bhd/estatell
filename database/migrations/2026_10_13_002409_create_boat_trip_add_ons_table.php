<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('boat_trip_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_boat_trip_id')->constrained('unit_boat_trips')->cascadeOnDelete();
            $table->foreignId('unit_add_on_id')->constrained('unit_add_ons')->cascadeOnDelete();
            $table->timestamps();

            $table->index('unit_boat_trip_id');
            $table->unique('unit_add_on_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boat_trip_add_ons');
    }
};
