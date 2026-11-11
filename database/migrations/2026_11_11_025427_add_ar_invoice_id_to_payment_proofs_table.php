<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The invoice a company slip is for, when the company paid for one invoiced booking. Accepting it fills that invoice
     * first. Only a company slip names an invoice. See docs/15-city-ledger/03-ar-payments.md#payment-slips.
     */
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->foreignId('ar_invoice_id')->nullable()->after('company_id')->constrained('ar_invoices')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payment_proofs ADD CONSTRAINT payment_proofs_ar_invoice_check CHECK (ar_invoice_id IS NULL OR company_id IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_proofs DROP CONSTRAINT payment_proofs_ar_invoice_check');

        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ar_invoice_id');
        });
    }
};
