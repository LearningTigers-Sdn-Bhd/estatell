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
        Schema::table('boat_departures', function (Blueprint $table) {
            $table->foreignId('default_boat_id')->nullable()->after('charge_item_id')->constrained('boats')->nullOnDelete();
            $table->string('default_crew', 120)->nullable()->after('default_boat_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boat_departures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_boat_id');
            $table->dropColumn('default_crew');
        });
    }
};
