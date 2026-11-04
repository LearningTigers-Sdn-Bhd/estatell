<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoice_submission_folios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('e_invoice_submissions')->cascadeOnDelete();
            $table->foreignId('folio_id')->constrained('folios')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['submission_id', 'folio_id']);
            $table->index('folio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_submission_folios');
    }
};
