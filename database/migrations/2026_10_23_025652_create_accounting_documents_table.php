<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ar_invoice_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ar_payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('kind');
            $table->string('status');
            $table->string('external_id')->nullable();
            $table->string('external_type')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['ar_invoice_id', 'provider']);
            $table->unique(['ar_payment_id', 'provider']);
        });

        DB::statement('ALTER TABLE accounting_documents ADD CONSTRAINT accounting_documents_one_subject CHECK ((ar_invoice_id IS NULL) <> (ar_payment_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_documents');
    }
};
