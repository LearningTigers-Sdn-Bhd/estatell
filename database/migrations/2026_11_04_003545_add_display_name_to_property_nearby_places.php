<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_nearby_places', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('nearby_place_id');
        });
    }

    public function down(): void
    {
        Schema::table('property_nearby_places', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
