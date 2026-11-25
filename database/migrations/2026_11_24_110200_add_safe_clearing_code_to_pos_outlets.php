<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The counter code for cash that changes hands between shifts: when a cashier opens with a different float than the
     * last shift counted, the difference is booked as a safe drop or float in against this code.
     */
    public function up(): void
    {
        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->foreignId('safe_clearing_transaction_code_id')->nullable()->after('cash_variance_transaction_code_id')->constrained('transaction_codes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('safe_clearing_transaction_code_id');
        });
    }
};
