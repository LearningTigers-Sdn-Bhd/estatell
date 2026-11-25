<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Native POS order and shift numbers get their own document sequences (type codes 10 and 11).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE document_sequences DROP CONSTRAINT document_sequences_type_check');
        DB::statement("ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_type_check CHECK (type IN ('reservation', 'folio', 'invoice', 'credit_note', 'receipt', 'registration_card', 'event', 'pos_order', 'pos_shift'))");
    }

    public function down(): void
    {
        DB::table('document_sequences')->whereIn('type', ['pos_order', 'pos_shift'])->delete();

        DB::statement('ALTER TABLE document_sequences DROP CONSTRAINT document_sequences_type_check');
        DB::statement("ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_type_check CHECK (type IN ('reservation', 'folio', 'invoice', 'credit_note', 'receipt', 'registration_card', 'event'))");
    }
};
