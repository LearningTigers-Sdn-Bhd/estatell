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
        Schema::create('channel_ota_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_rate_plan_mapping_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('occupancy');
            $table->string('ota_room_code', 40);
            $table->string('ota_rate_code', 40);
            $table->boolean('is_primary')->nullable();
            $table->string('rate_type', 40)->nullable();
            $table->timestamps();

            $table->unique(['channel_connection_id', 'channel_rate_plan_mapping_id', 'occupancy'], 'channel_ota_mappings_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_ota_mappings');
    }
};
