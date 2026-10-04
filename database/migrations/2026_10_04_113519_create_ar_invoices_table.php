<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ar_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('credit_for_invoice_id')->nullable()->constrained('ar_invoices')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('number', 50);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500)->nullable();
            $table->string('status', 20);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'issued_on']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX ar_invoices_number_unique ON ar_invoices (property_id, type, number) WHERE status <> 'void'");
        DB::statement("CREATE UNIQUE INDEX ar_invoices_folio_unique ON ar_invoices (folio_id) WHERE type = 'invoice' AND status <> 'void'");
        DB::statement("ALTER TABLE ar_invoices ADD CONSTRAINT ar_invoices_shape_check CHECK ((type = 'invoice' AND amount > 0 AND credit_for_invoice_id IS NULL AND due_on IS NOT NULL) OR (type = 'credit_note' AND amount < 0 AND credit_for_invoice_id IS NOT NULL AND folio_id IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ar_invoices');
    }
};
