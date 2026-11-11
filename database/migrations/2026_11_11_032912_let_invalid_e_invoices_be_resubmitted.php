<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * An Invalid e-invoice was rejected by LHDN, so it must not block a corrected resubmission. Rebuild the "one active
     * document per folio or AR invoice" indexes so they skip Invalid rows as well as Cancelled ones.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS e_invoice_submissions_active_folio_type');
        DB::statement('DROP INDEX IF EXISTS e_invoice_submissions_active_ar_type');
        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_folio_type ON e_invoice_submissions (folio_id, document_type) WHERE folio_id IS NOT NULL AND status NOT IN ('cancelled', 'invalid')");
        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_ar_type ON e_invoice_submissions (ar_invoice_id, document_type) WHERE ar_invoice_id IS NOT NULL AND status NOT IN ('cancelled', 'invalid')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS e_invoice_submissions_active_folio_type');
        DB::statement('DROP INDEX IF EXISTS e_invoice_submissions_active_ar_type');
        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_folio_type ON e_invoice_submissions (folio_id, document_type) WHERE folio_id IS NOT NULL AND status != 'cancelled'");
        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_ar_type ON e_invoice_submissions (ar_invoice_id, document_type) WHERE ar_invoice_id IS NOT NULL AND status != 'cancelled'");
    }
};
