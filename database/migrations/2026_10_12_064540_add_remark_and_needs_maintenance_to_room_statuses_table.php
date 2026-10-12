<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A remark staff write for a room, and the flag housekeeping raises when something in the room is broken.
     */
    public function up(): void
    {
        Schema::table('room_statuses', function (Blueprint $table) {
            $table->string('remark', 500)->nullable()->after('priority_note');
            $table->boolean('needs_maintenance')->default(false)->after('remark');
        });
    }

    public function down(): void
    {
        Schema::table('room_statuses', function (Blueprint $table) {
            $table->dropColumn(['remark', 'needs_maintenance']);
        });
    }
};
