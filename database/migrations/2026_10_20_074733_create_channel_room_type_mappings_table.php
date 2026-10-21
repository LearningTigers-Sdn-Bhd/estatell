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
        Schema::create('channel_room_type_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->string('external_room_type_id', 40);
            $table->timestamps();

            $table->unique(['channel_property_id', 'room_type_id']);
            $table->unique(['channel_property_id', 'external_room_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_room_type_mappings');
    }
};
