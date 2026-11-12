<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The e-invoice tables used `timestamptz`, unlike the rest of the schema. Laravel writes UTC times without an
     * offset, so on a database whose session time zone is not UTC they were stored hours off. Store them as plain
     * `timestamp` like every other table. Converting at the session time zone gives back the exact value Laravel wrote.
     */
    public function up(): void
    {
        foreach (['submitted_at', 'validated_at', 'cancelled_at'] as $column) {
            DB::statement("ALTER TABLE e_invoice_submissions ALTER COLUMN {$column} TYPE timestamp(0) without time zone USING {$column} AT TIME ZONE current_setting('TimeZone')");
        }

        DB::statement("ALTER TABLE property_e_invoice_settings ALTER COLUMN effective_from TYPE timestamp(0) without time zone USING effective_from AT TIME ZONE current_setting('TimeZone')");
    }

    public function down(): void
    {
        foreach (['submitted_at', 'validated_at', 'cancelled_at'] as $column) {
            DB::statement("ALTER TABLE e_invoice_submissions ALTER COLUMN {$column} TYPE timestamp(0) with time zone USING {$column} AT TIME ZONE current_setting('TimeZone')");
        }

        DB::statement("ALTER TABLE property_e_invoice_settings ALTER COLUMN effective_from TYPE timestamp(0) with time zone USING effective_from AT TIME ZONE current_setting('TimeZone')");
    }
};
