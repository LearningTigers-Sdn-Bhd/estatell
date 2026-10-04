<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_source_check');
        DB::table('reservations')->where('source', 'agent')->update(['source' => 'portal']);
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_source_check CHECK (source IN ('direct', 'walk_in', 'phone', 'channel', 'portal'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_source_check');
        DB::table('reservations')->where('source', 'portal')->update(['source' => 'agent']);
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_source_check CHECK (source IN ('direct', 'walk_in', 'phone', 'channel', 'agent'))");
    }
};
