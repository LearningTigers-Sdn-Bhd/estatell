<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guides and boats of the activities, and the guide and boat of each activity session on a date (a slot: one facility,
 * date and time). See docs/33-facilities/06-guides-and-boats.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('phone', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX facility_guides_property_name_unique ON facility_guides (property_id, lower(name))');

        Schema::create('facility_boats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedSmallInteger('seats')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX facility_boats_property_name_unique ON facility_boats (property_id, lower(name))');
        DB::statement('ALTER TABLE facility_boats ADD CONSTRAINT facility_boats_seats_check CHECK (seats IS NULL OR seats > 0)');

        Schema::create('facility_slot_crews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->date('slot_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('facility_guide_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('facility_boat_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['facility_id', 'slot_date', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE facility_slot_crews ADD CONSTRAINT facility_slot_crews_someone_check CHECK (facility_guide_id IS NOT NULL OR facility_boat_id IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_slot_crews');
        Schema::dropIfExists('facility_boats');
        Schema::dropIfExists('facility_guides');
    }
};
