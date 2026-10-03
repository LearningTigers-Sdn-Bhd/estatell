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
        Schema::create('folios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number');
            $table->string('type');
            $table->string('status');
            $table->foreignId('payer_guest_id')->nullable()->constrained('guests')->restrictOnDelete();
            $table->unsignedBigInteger('payer_company_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'number']);
            $table->index('reservation_id');
            $table->index('reservation_unit_id');
            $table->index('payer_guest_id');
        });

        DB::statement("ALTER TABLE folios ADD CONSTRAINT folios_type_check CHECK (type IN ('master', 'unit'))");
        DB::statement("ALTER TABLE folios ADD CONSTRAINT folios_status_check CHECK (status IN ('open', 'closed', 'voided'))");
        DB::statement('ALTER TABLE folios ADD CONSTRAINT folios_payer_check CHECK (payer_guest_id IS NULL OR payer_company_id IS NULL)');
        DB::statement("ALTER TABLE folios ADD CONSTRAINT folios_unit_check CHECK ((type = 'unit') = (reservation_unit_id IS NOT NULL))");
        DB::statement('ALTER TABLE folios ADD CONSTRAINT folios_closed_check CHECK ((closed_at IS NULL) = (closed_by IS NULL))');

        Schema::create('folio_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_code_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->date('posting_date');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('parent_line_id')->nullable()->constrained('folio_lines')->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('folio_lines')->restrictOnDelete();
            $table->uuid('transfer_group_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['folio_id', 'posting_date']);
            $table->index('transaction_code_id');
            $table->index('parent_line_id');
            $table->index('reversal_of_id');
            $table->index('transfer_group_id');
            $table->index(['source_type', 'source_id']);
        });

        DB::statement("ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_kind_check CHECK (kind IN ('charge', 'payment', 'adjustment', 'tax'))");
        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_quantity_check CHECK (quantity >= 1)');
        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_source_check CHECK ((source_type IS NULL) = (source_id IS NULL))');
        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_parent_check CHECK (parent_line_id IS NULL OR parent_line_id <> id)');
        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_reversal_check CHECK (reversal_of_id IS NULL OR reversal_of_id <> id)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION folio_lines_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'folio_lines is an immutable ledger: post a reversal line instead';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement('CREATE TRIGGER folio_lines_immutable BEFORE UPDATE OR DELETE ON folio_lines FOR EACH ROW EXECUTE FUNCTION folio_lines_immutable()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS folio_lines_immutable ON folio_lines');
        DB::statement('DROP FUNCTION IF EXISTS folio_lines_immutable()');
        Schema::dropIfExists('folio_lines');
        Schema::dropIfExists('folios');
    }
};
