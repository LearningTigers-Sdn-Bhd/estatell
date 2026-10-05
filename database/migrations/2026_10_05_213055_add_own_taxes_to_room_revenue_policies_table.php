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
        Schema::table('room_revenue_policies', function (Blueprint $table) {
            $table->boolean('own_taxes')->default(false)->after('free_until_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_revenue_policies', function (Blueprint $table) {
            $table->dropColumn('own_taxes');
        });
    }
};
