<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rooms are assigned automatically by default, as in wastays, and every existing property is switched on. A
     * property that wants to pick rooms switches it off in Settings. See docs/02-property/02-property-settings.md.
     */
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->boolean('auto_assign_rooms')->default(true)->change();
        });

        DB::table('property_settings')->where('auto_assign_rooms', false)->update(['auto_assign_rooms' => true]);
    }

    /**
     * Put the default back. The switched on properties stay on: which ones were off before is not kept.
     */
    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->boolean('auto_assign_rooms')->default(false)->change();
        });
    }
};
