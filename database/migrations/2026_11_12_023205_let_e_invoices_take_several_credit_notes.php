<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "One live document per stay or City ledger invoice" is meant for the e-invoice (`01`) only. It also caught credit
 * and refund notes, so a second note on the same e-invoice failed. Limit both indexes to e-invoices: a stay is still
 * reported once (its own e-invoice or its City ledger invoice's, which carries its folio), and an e-invoice can take
 * several notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS e_invoice_submissions_active_folio_type');
        DB::statement('DROP INDEX IF EXISTS e_invoice_submissions_active_ar_type');
        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_folio_type ON e_invoice_submissions (folio_id, document_type) WHERE folio_id IS NOT NULL AND document_type = '01' AND status NOT IN ('cancelled', 'invalid')");
        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_ar_type ON e_invoice_submissions (ar_invoice_id, document_type) WHERE ar_invoice_id IS NOT NULL AND document_type = '01' AND status NOT IN ('cancelled', 'invalid')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS e_invoice_submissions_active_folio_type');
        DB::statement('DROP INDEX IF EXISTS e_invoice_submissions_active_ar_type');
        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_folio_type ON e_invoice_submissions (folio_id, document_type) WHERE folio_id IS NOT NULL AND status NOT IN ('cancelled', 'invalid')");
        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_ar_type ON e_invoice_submissions (ar_invoice_id, document_type) WHERE ar_invoice_id IS NOT NULL AND status NOT IN ('cancelled', 'invalid')");
    }
};
