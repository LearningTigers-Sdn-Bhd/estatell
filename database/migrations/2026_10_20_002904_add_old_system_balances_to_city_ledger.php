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
        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->boolean('migrated')->default(false)->after('reason');
        });

        DB::statement('ALTER TABLE ar_payments ALTER COLUMN receipt_number DROP NOT NULL');
        DB::statement('ALTER TABLE ar_payments ADD CONSTRAINT ar_payments_receipt_check CHECK (receipt_number IS NOT NULL OR reference IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE ar_payments DROP CONSTRAINT ar_payments_receipt_check');
        DB::statement('ALTER TABLE ar_payments ALTER COLUMN receipt_number SET NOT NULL');

        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->dropColumn('migrated');
        });
    }
};
