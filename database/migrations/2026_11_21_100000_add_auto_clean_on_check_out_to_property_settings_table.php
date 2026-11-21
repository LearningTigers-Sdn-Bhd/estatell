<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property switch: a room goes Clean, not Dirty, when its last guest checks out. Off by default, so no property changes by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->boolean('auto_clean_on_check_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('auto_clean_on_check_out');
        });
    }
};
