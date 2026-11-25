<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 kitchen routing: stations per outlet (Hot Kitchen, Bar, Cold), and the station a category, item or fired line
     * goes to. Deleting a station unroutes its items rather than deleting them. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
     */
    public function up(): void
    {
        Schema::create('pos_kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('mode', 20)->default('kds');
            $table->boolean('use_internal_name')->default(false);
            $table->unsignedSmallInteger('warn_after_minutes')->default(10);
            $table->unsignedSmallInteger('alert_after_minutes')->default(20);
            $table->string('filter_dining_kinds', 120)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX pos_kitchen_stations_name_unique ON pos_kitchen_stations (pos_outlet_id, lower(name))');
        DB::statement("ALTER TABLE pos_kitchen_stations ADD CONSTRAINT pos_kitchen_stations_mode_check CHECK (mode IN ('kds', 'browser_print', 'posport_printer', 'both'))");
        DB::statement('ALTER TABLE pos_kitchen_stations ADD CONSTRAINT pos_kitchen_stations_alert_check CHECK (warn_after_minutes >= 1 AND alert_after_minutes > warn_after_minutes)');

        foreach (['pos_categories', 'pos_items', 'pos_order_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->foreignId('kitchen_station_id')->nullable()->after('property_id')->constrained('pos_kitchen_stations')->nullOnDelete();
                $table->index(['property_id', 'kitchen_station_id'], $tableName.'_kitchen_station_index');
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_order_lines', 'pos_items', 'pos_categories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName.'_kitchen_station_index');
                $table->dropConstrainedForeignId('kitchen_station_id');
            });
        }

        Schema::dropIfExists('pos_kitchen_stations');
    }
};
