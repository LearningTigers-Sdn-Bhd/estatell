<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a guest needs to know about a facility the property offers: where it is, when it is open, what it costs and whether
 * it must be reserved. It lives on the row that ticks the amenity, so un-ticking the amenity removes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_amenities', function (Blueprint $table) {
            $table->string('location', 200)->nullable();
            $table->string('opening_hours', 300)->nullable();
            $table->string('fee_information', 300)->nullable();
            $table->boolean('reservation_required')->default(false);
            $table->string('reservation_instructions', 500)->nullable();
            $table->string('guest_notes', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('property_amenities', function (Blueprint $table) {
            $table->dropColumn(['location', 'opening_hours', 'fee_information', 'reservation_required', 'reservation_instructions', 'guest_notes']);
        });
    }
};
