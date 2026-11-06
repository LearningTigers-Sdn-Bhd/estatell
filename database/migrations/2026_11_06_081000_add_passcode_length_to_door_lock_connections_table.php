<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('door_lock_connections', function (Blueprint $table) {
            $table->unsignedTinyInteger('passcode_length')->default(6)->after('passcode_mode');
        });
    }

    public function down(): void
    {
        Schema::table('door_lock_connections', function (Blueprint $table) {
            $table->dropColumn('passcode_length');
        });
    }
};
