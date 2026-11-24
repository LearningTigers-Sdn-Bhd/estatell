<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A room charge can be refunded in parts. Each link keeps how much net and tax was credited back so far, so the last part
     * credits exactly what is left and the folio never drifts a sen from the receipt.
     */
    public function up(): void
    {
        Schema::table('pos_order_folio_links', function (Blueprint $table) {
            $table->decimal('refunded_net', 12, 2)->default(0)->after('gross_amount');
            $table->decimal('refunded_tax', 12, 2)->default(0)->after('refunded_net');
        });

        DB::statement('ALTER TABLE pos_order_folio_links ADD CONSTRAINT pos_order_folio_links_refund_check CHECK (refunded_net >= 0 AND refunded_tax >= 0 AND refunded_net <= net_amount AND refunded_tax <= tax_amount)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pos_order_folio_links DROP CONSTRAINT pos_order_folio_links_refund_check');

        Schema::table('pos_order_folio_links', function (Blueprint $table) {
            $table->dropColumn(['refunded_net', 'refunded_tax']);
        });
    }
};
