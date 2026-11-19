<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_walk_in_holds', function (Blueprint $table) {
            $table->timestamp('announced_at')->nullable()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_walk_in_holds', function (Blueprint $table) {
            $table->dropColumn('announced_at');
        });
    }
};
