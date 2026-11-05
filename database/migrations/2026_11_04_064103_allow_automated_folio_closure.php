<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE folios DROP CONSTRAINT folios_closed_check');
        DB::statement('ALTER TABLE folios ADD CONSTRAINT folios_closed_check CHECK (closed_by IS NULL OR closed_at IS NOT NULL)');
    }

    public function down(): void
    {
        if (DB::table('folios')->whereNotNull('closed_at')->whereNull('closed_by')->exists()) {
            throw new RuntimeException('Automated folio closures exist. Keep this migration and use a forward fix.');
        }

        DB::statement('ALTER TABLE folios DROP CONSTRAINT folios_closed_check');
        DB::statement('ALTER TABLE folios ADD CONSTRAINT folios_closed_check CHECK ((closed_at IS NULL) = (closed_by IS NULL))');
    }
};
