<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->string('kiosk_key_mode')->default('pin');
            $table->boolean('kiosk_walk_in_enabled')->default(false);
            $table->unsignedSmallInteger('kiosk_idle_timeout_seconds')->default(60);
        });

        DB::statement("alter table property_settings add constraint property_settings_kiosk_key_mode_check check (kiosk_key_mode in ('pin', 'card', 'both'))");
        DB::statement('alter table property_settings add constraint property_settings_kiosk_idle_timeout_check check (kiosk_idle_timeout_seconds between 15 and 600)');
    }

    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn(['kiosk_key_mode', 'kiosk_walk_in_enabled', 'kiosk_idle_timeout_seconds']);
        });
    }
};
