<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every existing property its print settings row with the defaults. New properties get it from
     * SeedPropertyDefaults.
     */
    public function up(): void
    {
        DB::statement('INSERT INTO print_settings (property_id, created_at, updated_at) SELECT id, NOW(), NOW() FROM properties ON CONFLICT (property_id) DO NOTHING');
    }

    /**
     * Nothing to undo: rolling back the create migration drops the rows.
     */
    public function down(): void {}
};
