<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. `received_on` is the day the money came in. Existing rows take the service date of their
     * payment line: the posting date, or the day the old PMS took the money for an Old system payment. The payments
     * trigger is off for the backfill only.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->date('received_on')->nullable()->after('reference');
            $table->string('notes', 500)->nullable()->after('received_on');
        });

        DB::statement('ALTER TABLE payments DISABLE TRIGGER payments_immutable');
        DB::statement(<<<'SQL'
            UPDATE payments SET received_on = folio_lines.service_date
            FROM folio_lines
            WHERE folio_lines.id = payments.folio_line_id
            SQL);
        DB::statement('ALTER TABLE payments ENABLE TRIGGER payments_immutable');

        DB::statement('ALTER TABLE payments ALTER COLUMN received_on SET NOT NULL');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_notes_check CHECK (notes IS NULL OR (notes = btrim(notes) AND notes <> ''))");
        DB::statement('CREATE INDEX payments_property_id_received_on_index ON payments (property_id, received_on)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_property_id_received_on_index');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_notes_check');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['received_on', 'notes']);
        });
    }
};
