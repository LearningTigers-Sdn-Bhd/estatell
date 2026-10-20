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
        Schema::create('channel_rate_plan_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->string('external_rate_plan_id', 40);
            $table->timestamps();

            $table->unique(['channel_property_id', 'rate_plan_id', 'room_type_id']);
            $table->unique(['channel_property_id', 'external_rate_plan_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_rate_plan_mappings');
    }
};
