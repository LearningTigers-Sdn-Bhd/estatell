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
        Schema::create('registration_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_guest_id')->constrained()->restrictOnDelete();
            $table->string('number', 30)->nullable();
            $table->string('status', 10);
            $table->foreignId('identity_document_id')->nullable()->constrained('guest_identity_documents')->nullOnDelete();
            $table->foreignId('terms_id')->nullable()->constrained('registration_card_terms')->restrictOnDelete();
            $table->string('vehicle_plate', 20)->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->string('signer_name', 150)->nullable();
            $table->string('void_reason', 250)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'number']);
            $table->index('unit_guest_id');
        });

        DB::statement("ALTER TABLE registration_cards ADD CONSTRAINT registration_cards_status_check CHECK (status IN ('draft', 'signed', 'void'))");
        DB::statement("ALTER TABLE registration_cards ADD CONSTRAINT registration_cards_signed_check CHECK (status <> 'signed' OR (signed_at IS NOT NULL AND terms_id IS NOT NULL AND snapshot IS NOT NULL AND number IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX registration_cards_one_open_per_unit_guest ON registration_cards (unit_guest_id) WHERE status <> 'void'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_cards');
    }
};
