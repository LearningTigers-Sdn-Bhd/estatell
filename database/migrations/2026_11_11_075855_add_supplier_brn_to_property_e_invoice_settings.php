<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hotel's company registration number (SSM) as sent to LHDN, next to the other e-invoice details, so a hotel
     * sees and confirms every detail in one place. Empty falls back to the property's registration number.
     */
    public function up(): void
    {
        Schema::table('property_e_invoice_settings', function (Blueprint $table) {
            $table->string('supplier_brn', 40)->nullable()->after('supplier_tin');
        });
    }

    public function down(): void
    {
        Schema::table('property_e_invoice_settings', function (Blueprint $table) {
            $table->dropColumn('supplier_brn');
        });
    }
};
