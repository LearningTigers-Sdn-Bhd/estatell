<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A proof can now be for a company's city ledger account as well as for one booking. A company slip has no
     * reservation, and accepting it records a city ledger payment.
     */
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->foreignId('reservation_id')->nullable()->change();
            $table->foreignId('company_id')->nullable()->after('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ar_payment_id')->nullable()->after('payment_id')->constrained('ar_payments')->restrictOnDelete();
            $table->index(['company_id', 'status']);
        });

        DB::statement('ALTER TABLE payment_proofs DROP CONSTRAINT payment_proofs_check');
        DB::statement("ALTER TABLE payment_proofs ADD CONSTRAINT payment_proofs_check CHECK (amount > 0 AND status IN ('submitted', 'accepted', 'rejected') AND (reservation_id IS NOT NULL OR company_id IS NOT NULL) AND (status <> 'accepted' OR payment_id IS NOT NULL OR ar_payment_id IS NOT NULL) AND (status <> 'rejected' OR reject_reason IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('payment_proofs')->whereNull('reservation_id')->delete();
        DB::statement('ALTER TABLE payment_proofs DROP CONSTRAINT payment_proofs_check');
        DB::statement("ALTER TABLE payment_proofs ADD CONSTRAINT payment_proofs_check CHECK (amount > 0 AND status IN ('submitted', 'accepted', 'rejected') AND (status <> 'accepted' OR payment_id IS NOT NULL) AND (status <> 'rejected' OR reject_reason IS NOT NULL))");

        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
            $table->dropConstrainedForeignId('ar_payment_id');
            $table->dropConstrainedForeignId('company_id');
            $table->foreignId('reservation_id')->nullable(false)->change();
        });
    }
};
