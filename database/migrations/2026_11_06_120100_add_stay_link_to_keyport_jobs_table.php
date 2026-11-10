<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyport_jobs', function (Blueprint $table) {
            $table->foreignId('door_lock_id')->nullable()->after('device_id')->constrained()->nullOnDelete();
            $table->foreignId('reservation_unit_id')->nullable()->after('door_lock_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('keyport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_unit_id');
            $table->dropConstrainedForeignId('door_lock_id');
        });
    }
};
