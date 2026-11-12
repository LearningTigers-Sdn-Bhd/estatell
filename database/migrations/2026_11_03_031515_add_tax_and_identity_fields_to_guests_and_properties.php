<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('tin', 30)->nullable()->after('phone');
            $table->string('state_code', 2)->nullable()->after('state');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->string('msic_code', 5)->nullable()->after('tin');
            $table->string('business_description', 255)->nullable()->after('msic_code');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['msic_code', 'business_description']);
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn(['tin', 'state_code']);
        });
    }
};
