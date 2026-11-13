<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Who pays the taxes collected at check-in on this booking. Null follows the company's
     * `pays_collected_taxes`.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('collected_taxes_payer', 10)->nullable()->after('billing_mode');
        });

        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_collected_taxes_payer_check CHECK (collected_taxes_payer IN ('company', 'guest'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('collected_taxes_payer');
        });
    }
};
