<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff ticked "Save to the guest profile" in the Issue e-invoice form. The tax number and ID are saved once LHDN
     * accepts the e-invoice, then the flag is cleared (`SaveBuyerToGuest`).
     */
    public function up(): void
    {
        Schema::table('e_invoice_submissions', function (Blueprint $table) {
            $table->boolean('save_buyer_to_guest')->default(false)->after('requested_by_guest');
        });
    }

    public function down(): void
    {
        Schema::table('e_invoice_submissions', function (Blueprint $table) {
            $table->dropColumn('save_buyer_to_guest');
        });
    }
};
