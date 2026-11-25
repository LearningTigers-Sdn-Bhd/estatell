<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many times a receipt's public claim link was tried, so a stolen or guessed slip cannot be tried without end. See
     * docs/34-native-pos/05-shifts-audit-and-einvoice.md.
     */
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('receipt_claim_attempts')->default(0);
        });
    }
};
