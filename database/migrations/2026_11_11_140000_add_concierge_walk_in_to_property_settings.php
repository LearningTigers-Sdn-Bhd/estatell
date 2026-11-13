<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->boolean('concierge_walk_in_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('concierge_walk_in_enabled');
        });
    }
};
