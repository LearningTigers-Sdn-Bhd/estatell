<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A refund has no receipt number, and a payment recorded in the old system has none either: estatell did not
     * receive that money. Every other payment still gets one in `RecordPayment`.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_receipt_check');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_receipt_check CHECK (refund_of_id IS NULL OR receipt_number IS NULL)');
    }

    /**
     * Restore the old rule. It fails when a payment without a receipt number exists.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_receipt_check');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_receipt_check CHECK ((refund_of_id IS NULL) = (receipt_number IS NOT NULL))');
    }
};
