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
        Schema::create('ar_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('received_on');
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('receipt_number', 50);
            $table->string('idempotency_key', 64);
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'receipt_number']);
            $table->unique(['property_id', 'idempotency_key']);
            $table->index(['company_id', 'received_on']);
        });

        DB::statement('ALTER TABLE ar_payments ADD CONSTRAINT ar_payments_amount_check CHECK (amount > 0)');

        Schema::create('ar_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ar_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('ar_invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->index('ar_payment_id');
            $table->index('ar_invoice_id');
        });

        DB::statement('ALTER TABLE ar_allocations ADD CONSTRAINT ar_allocations_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE ar_allocations ADD CONSTRAINT ar_allocations_reversal_check CHECK ((reversed_at IS NULL) = (reversed_by IS NULL) AND (reversed_at IS NULL OR reversal_reason IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ar_allocations');
        Schema::dropIfExists('ar_payments');
    }
};
