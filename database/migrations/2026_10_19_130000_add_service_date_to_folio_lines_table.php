<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A night line takes its unit night, and a child, a reversal or a transfer copy takes the
     * date of the line it comes from. Every other line takes its posting date. The ledger trigger is off for the
     * backfill only.
     */
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->date('service_date')->nullable()->after('posting_date');
        });

        DB::statement('ALTER TABLE folio_lines DISABLE TRIGGER folio_lines_immutable');

        DB::statement(<<<'SQL'
            UPDATE folio_lines SET service_date = LEAST(unit_nights.date, folio_lines.posting_date)
            FROM unit_nights
            WHERE folio_lines.source_type = 'App\Models\UnitNight' AND folio_lines.source_id = unit_nights.id
              AND folio_lines.parent_line_id IS NULL AND folio_lines.reversal_of_id IS NULL
            SQL);

        DB::statement(<<<'SQL'
            UPDATE folio_lines SET service_date = posting_date
            WHERE service_date IS NULL AND parent_line_id IS NULL AND reversal_of_id IS NULL AND transfer_group_id IS NULL
            SQL);

        do {
            $resolved = DB::affectingStatement(<<<'SQL'
                UPDATE folio_lines SET service_date = LEAST(origin.service_date, folio_lines.posting_date)
                FROM folio_lines origin
                WHERE folio_lines.service_date IS NULL AND origin.service_date IS NOT NULL
                  AND origin.id = COALESCE(
                      folio_lines.reversal_of_id,
                      CASE WHEN folio_lines.transfer_group_id IS NOT NULL AND folio_lines.parent_line_id IS NULL
                           THEN NULLIF(split_part(folio_lines.idempotency_key, ':', 3), '')::bigint END,
                      folio_lines.parent_line_id
                  )
                SQL);
        } while ($resolved > 0);

        DB::statement('UPDATE folio_lines SET service_date = posting_date WHERE service_date IS NULL');
        DB::statement('ALTER TABLE folio_lines ENABLE TRIGGER folio_lines_immutable');

        DB::statement('ALTER TABLE folio_lines ALTER COLUMN service_date SET NOT NULL');
        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_service_date_check CHECK (service_date <= posting_date)');
        DB::statement('CREATE INDEX folio_lines_folio_id_service_date_index ON folio_lines (folio_id, service_date)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS folio_lines_folio_id_service_date_index');
        DB::statement('ALTER TABLE folio_lines DROP CONSTRAINT IF EXISTS folio_lines_service_date_check');

        Schema::table('folio_lines', function (Blueprint $table) {
            $table->dropColumn('service_date');
        });
    }
};
