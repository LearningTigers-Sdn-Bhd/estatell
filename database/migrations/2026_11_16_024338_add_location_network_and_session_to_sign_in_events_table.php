<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sign_in_events', function (Blueprint $table) {
            $table->string('city', 80)->nullable();
            $table->string('region', 80)->nullable();
            $table->bigInteger('asn')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('signed_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sign_in_events', function (Blueprint $table) {
            $table->dropColumn(['city', 'region', 'asn', 'last_active_at', 'signed_out_at']);
        });
    }
};
