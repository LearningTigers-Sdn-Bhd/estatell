<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('door_locks', function (Blueprint $table) {
            $table->string('gateway_name')->nullable()->after('has_gateway');
            $table->boolean('gateway_online')->nullable()->after('gateway_name');
            $table->integer('clock_drift_seconds')->nullable()->after('gateway_online');
            $table->timestamp('health_checked_at')->nullable()->after('clock_drift_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('door_locks', function (Blueprint $table) {
            $table->dropColumn(['gateway_name', 'gateway_online', 'clock_drift_seconds', 'health_checked_at']);
        });
    }
};
