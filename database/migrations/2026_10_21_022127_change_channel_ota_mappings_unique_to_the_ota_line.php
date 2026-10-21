<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An OTA line (room, rate and guest count) maps to one estatell plan occupancy, but one plan occupancy may take
     * several OTA lines, as Channex allows.
     */
    public function up(): void
    {
        Schema::table('channel_ota_mappings', function (Blueprint $table) {
            $table->dropUnique('channel_ota_mappings_unique');
            $table->unique(['channel_connection_id', 'ota_room_code', 'ota_rate_code', 'occupancy'], 'channel_ota_mappings_line_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_ota_mappings', function (Blueprint $table) {
            $table->dropUnique('channel_ota_mappings_line_unique');
            $table->unique(['channel_connection_id', 'channel_rate_plan_mapping_id', 'occupancy'], 'channel_ota_mappings_unique');
        });
    }
};
