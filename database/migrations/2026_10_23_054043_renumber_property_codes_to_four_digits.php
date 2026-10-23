<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Number the properties of each prefix from 1101 in creation order (ES10142 becomes ES1103) and set each
     * counter to the last number given. A journal reference includes the code, so the rename stops when a
     * journal has already been pushed to an accounting provider.
     */
    public function up(): void
    {
        if (Schema::hasTable('accounting_syncs') && DB::table('accounting_syncs')->whereNotNull('pushed_at')->exists()) {
            throw new RuntimeException('A journal was already pushed to an accounting provider, so property codes cannot be renumbered.');
        }

        DB::statement('ALTER TABLE properties DROP CONSTRAINT properties_code_check');

        DB::statement('
            UPDATE properties SET code = numbered.new_code
            FROM (
                SELECT id, left(code, 2) || (1100 + row_number() OVER (PARTITION BY left(code, 2) ORDER BY created_at, id)) AS new_code
                FROM properties
            ) AS numbered
            WHERE properties.id = numbered.id
        ');

        DB::statement('UPDATE property_code_counters SET last_number = 1100');
        DB::statement('
            UPDATE property_code_counters SET last_number = latest.last_number
            FROM (
                SELECT left(code, 2) AS prefix, max(substr(code, 3)::bigint) AS last_number
                FROM properties
                GROUP BY left(code, 2)
            ) AS latest
            WHERE property_code_counters.prefix = latest.prefix
        ');

        DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_code_check CHECK (code ~ '^[A-Z]{2}[0-9]{4,}$')");
    }

    /**
     * The old numbers are not kept, so this cannot restore them.
     */
    public function down(): void {}
};
