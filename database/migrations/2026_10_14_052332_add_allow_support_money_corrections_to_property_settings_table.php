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
        Schema::table('property_settings', function (Blueprint $table) {
            $table->boolean('allow_support_money_corrections')->default(true)->after('allow_support_access');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('allow_support_money_corrections');
        });
    }
};
