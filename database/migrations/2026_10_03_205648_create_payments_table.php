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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('folio_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('purpose', 30);
            $table->string('status', 20);
            $table->string('reference', 100)->nullable();
            $table->foreignId('refund_of_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('receipt_number')->nullable();
            $table->foreignId('folio_line_id')->unique()->constrained('folio_lines')->restrictOnDelete();
            $table->foreignId('surcharge_line_id')->nullable()->unique()->constrained('folio_lines')->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['property_id', 'receipt_number']);
            $table->index('folio_id');
            $table->index('payment_method_id');
            $table->index('refund_of_id');
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_purpose_check CHECK (purpose IN ('advance', 'settlement', 'security_deposit'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'completed', 'failed'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_receipt_check CHECK ((refund_of_id IS NULL) = (receipt_number IS NOT NULL))');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refund_surcharge_check CHECK (refund_of_id IS NULL OR surcharge_line_id IS NULL)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refund_of_check CHECK (refund_of_id IS NULL OR refund_of_id <> id)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_reference_check CHECK (reference IS NULL OR (reference = btrim(reference) AND reference <> ''))");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION payments_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'payments are immutable: record a refund instead';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement('CREATE TRIGGER payments_immutable BEFORE UPDATE OR DELETE ON payments FOR EACH ROW EXECUTE FUNCTION payments_immutable()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payments_immutable ON payments');
        DB::statement('DROP FUNCTION IF EXISTS payments_immutable()');
        Schema::dropIfExists('payments');
    }
};
