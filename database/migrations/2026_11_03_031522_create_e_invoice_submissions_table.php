<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoice_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ar_invoice_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('original_submission_id')->nullable()->constrained('e_invoice_submissions')->nullOnDelete();

            $table->string('document_type', 10);
            $table->string('scenario', 30);
            $table->string('status', 20)->default('pending');
            $table->string('internal_id', 100)->index();

            $table->string('uuid', 100)->nullable()->index();
            $table->string('submission_uid', 100)->nullable()->index();
            $table->string('long_id', 150)->nullable();

            $table->jsonb('buyer_snapshot')->nullable();
            $table->jsonb('document_payload')->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->jsonb('error_details')->nullable();

            $table->boolean('is_consolidated')->default(false);
            $table->boolean('requested_by_guest')->default(false);
            $table->text('cancel_reason')->nullable();

            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'created_at']);
        });

        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_folio_type ON e_invoice_submissions (folio_id, document_type) WHERE folio_id IS NOT NULL AND status != 'cancelled'");
        DB::statement("CREATE UNIQUE INDEX e_invoice_submissions_active_ar_type ON e_invoice_submissions (ar_invoice_id, document_type) WHERE ar_invoice_id IS NOT NULL AND status != 'cancelled'");
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_submissions');
    }
};
