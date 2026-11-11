<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nearby_places', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('google_maps_url', 2048);
            $table->string('fingerprint')->unique();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        DB::statement("ALTER TABLE nearby_places ADD CONSTRAINT nearby_places_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");

        Schema::create('property_nearby_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nearby_place_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['property_id', 'nearby_place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_nearby_places');
        Schema::dropIfExists('nearby_places');
    }
};
