<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payout vouchers, the link from ledger entries to their voucher, and the lock that keeps commission entries immutable
 * except for that link. See docs/30-commission-fees/04-payout-vouchers.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');
            $table->decimal('amount', 12, 2);
            $table->string('status', 20);
            $table->jsonb('snapshot')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('paid_on')->nullable();
            $table->string('paid_reference', 100)->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'period_end']);
            $table->index(['status', 'due_on']);
        });

        DB::statement("ALTER TABLE payout_vouchers ADD CONSTRAINT payout_vouchers_status_check CHECK (status IN ('draft', 'approved', 'paid', 'carried_forward'))");
        DB::statement('ALTER TABLE payout_vouchers ADD CONSTRAINT payout_vouchers_period_check CHECK (period_start <= period_end AND period_end < due_on)');

        Schema::table('commission_entries', function (Blueprint $table) {
            $table->foreignId('payout_voucher_id')->nullable()->after('gateway_refund_id')->constrained()->restrictOnDelete();
            $table->index(['property_id', 'payout_voucher_id']);
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION commission_entries_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND OLD.payout_voucher_id IS NULL
                    AND NEW.payout_voucher_id IS NOT NULL
                    AND (to_jsonb(NEW) - 'payout_voucher_id') = (to_jsonb(OLD) - 'payout_voucher_id') THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'commission_entries is an immutable ledger: post a reversal entry instead';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement('CREATE TRIGGER commission_entries_immutable BEFORE UPDATE OR DELETE ON commission_entries FOR EACH ROW EXECUTE FUNCTION commission_entries_immutable()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS commission_entries_immutable ON commission_entries');
        DB::statement('DROP FUNCTION IF EXISTS commission_entries_immutable()');

        Schema::table('commission_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_voucher_id');
        });

        Schema::dropIfExists('payout_vouchers');
    }
};
