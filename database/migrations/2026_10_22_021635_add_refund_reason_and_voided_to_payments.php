<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A refund to another method keeps its reason, and a void marks a refund of a whole payment taken the same day.
     * Only a refund has either. The table stays append-only: the trigger rejects UPDATE and DELETE, not new columns.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('refund_reason', 255)->nullable();
            $table->boolean('voided')->default(false);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refund_only_check CHECK (refund_of_id IS NOT NULL OR (refund_reason IS NULL AND voided = false))');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_refund_reason_check CHECK (refund_reason IS NULL OR (refund_reason = btrim(refund_reason) AND refund_reason <> ''))");
    }

    /**
     * Drop the columns. The immutability trigger only guards rows, so the table can change.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_refund_reason_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_refund_only_check');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['refund_reason', 'voided']);
        });
    }
};
