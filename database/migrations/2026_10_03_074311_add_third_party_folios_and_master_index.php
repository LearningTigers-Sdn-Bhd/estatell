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
        DB::statement('ALTER TABLE folios DROP CONSTRAINT folios_type_check');
        DB::statement("ALTER TABLE folios ADD CONSTRAINT folios_type_check CHECK (type IN ('master', 'unit', 'third_party'))");
        DB::statement("CREATE UNIQUE INDEX folios_active_master_unique ON folios (reservation_id) WHERE type = 'master' AND status <> 'voided'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX folios_active_master_unique');
        DB::statement('ALTER TABLE folios DROP CONSTRAINT folios_type_check');
        DB::statement("ALTER TABLE folios ADD CONSTRAINT folios_type_check CHECK (type IN ('master', 'unit'))");
    }
};
