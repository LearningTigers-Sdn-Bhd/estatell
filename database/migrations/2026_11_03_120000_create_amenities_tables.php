<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->string('category', 60);
            $table->string('icon')->nullable();
            $table->unsignedInteger('position');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'name']);
        });

        Schema::create('property_amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->restrictOnDelete();

            $table->unique(['property_id', 'amenity_id']);
        });

        Schema::create('room_type_amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->restrictOnDelete();

            $table->unique(['room_type_id', 'amenity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_amenities');
        Schema::dropIfExists('property_amenities');
        Schema::dropIfExists('amenities');
    }
};
