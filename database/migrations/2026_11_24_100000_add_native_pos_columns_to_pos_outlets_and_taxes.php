<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Native POS shares `pos_outlets` with the external POS integration. A native outlet needs no charge item, because
     * every sold item resolves to its own transaction code. Taxes get an explicit LHDN tax type. See docs/34-native-pos/02-menu-catalog.md.
     */
    public function up(): void
    {
        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->unsignedBigInteger('default_charge_item_id')->nullable()->change();
            $table->boolean('native_pos_enabled')->default(false)->after('active');
            $table->foreignId('service_charge_transaction_code_id')->nullable()->after('native_pos_enabled')->constrained('transaction_codes')->restrictOnDelete();
            $table->foreignId('packaging_transaction_code_id')->nullable()->after('service_charge_transaction_code_id')->constrained('transaction_codes')->restrictOnDelete();
            $table->foreignId('rounding_transaction_code_id')->nullable()->after('packaging_transaction_code_id')->constrained('transaction_codes')->restrictOnDelete();
            $table->foreignId('cash_variance_transaction_code_id')->nullable()->after('rounding_transaction_code_id')->constrained('transaction_codes')->restrictOnDelete();
            $table->string('operation_mode', 20)->default('pay_first')->after('cash_variance_transaction_code_id');
            $table->time('close_sales_time')->default('00:00:00')->after('operation_mode');
            $table->string('sst_registration_number', 50)->nullable()->after('close_sales_time');
            $table->string('rounding_mode', 20)->default('five_sen')->after('sst_registration_number');
            $table->string('rounding_policy', 30)->default('cash_leg_only')->after('rounding_mode');
            $table->decimal('room_charge_limit', 12, 2)->nullable()->after('rounding_policy');
            $table->boolean('require_room_charge_signature')->default(true)->after('room_charge_limit');
            $table->boolean('require_meal_redemption_confirmation')->default(true)->after('require_room_charge_signature');
            $table->boolean('require_reprint_pin')->default(true)->after('require_meal_redemption_confirmation');
            $table->boolean('require_dual_control')->default(false)->after('require_reprint_pin');
            $table->boolean('print_receipt_after_payment')->default(true)->after('require_dual_control');
            $table->boolean('print_drawer_slip')->default(true)->after('print_receipt_after_payment');
            $table->boolean('require_clock_in')->default(false)->after('print_drawer_slip');
            $table->boolean('dynamic_qr_single_use')->default(true)->after('require_clock_in');
            $table->unsignedSmallInteger('dynamic_qr_ttl_minutes')->default(180)->after('dynamic_qr_single_use');
            $table->boolean('allow_anonymous_qr')->default(false)->after('dynamic_qr_ttl_minutes');
            $table->boolean('allow_qr_customer_remark')->default(true)->after('allow_anonymous_qr');
            $table->boolean('qr_ignore_stock')->default(false)->after('allow_qr_customer_remark');
        });

        DB::statement('ALTER TABLE pos_outlets ADD CONSTRAINT pos_outlets_charge_item_check CHECK (default_charge_item_id IS NOT NULL OR native_pos_enabled)');
        DB::statement("ALTER TABLE pos_outlets ADD CONSTRAINT pos_outlets_operation_mode_check CHECK (operation_mode IN ('pay_first', 'pay_later'))");
        DB::statement("ALTER TABLE pos_outlets ADD CONSTRAINT pos_outlets_rounding_mode_check CHECK (rounding_mode IN ('none', 'five_sen', 'ten_sen'))");
        DB::statement("ALTER TABLE pos_outlets ADD CONSTRAINT pos_outlets_rounding_policy_check CHECK (rounding_policy IN ('cash_leg_only', 'whole_bill_when_cash_present'))");
        DB::statement('ALTER TABLE pos_outlets ADD CONSTRAINT pos_outlets_room_charge_limit_check CHECK (room_charge_limit IS NULL OR room_charge_limit >= 0)');

        Schema::table('taxes', function (Blueprint $table) {
            $table->string('lhdn_tax_type', 4)->nullable()->after('code');
        });

        DB::statement("ALTER TABLE taxes ADD CONSTRAINT taxes_lhdn_tax_type_check CHECK (lhdn_tax_type IS NULL OR lhdn_tax_type IN ('02', '03', '06'))");
        DB::table('taxes')->whereRaw("upper(code) = 'SST'")->update(['lhdn_tax_type' => '02']);
        DB::table('taxes')->whereRaw("upper(code) = 'TTX'")->update(['lhdn_tax_type' => '03']);
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropColumn('lhdn_tax_type');
        });

        DB::statement('ALTER TABLE pos_outlets DROP CONSTRAINT pos_outlets_charge_item_check');

        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_charge_transaction_code_id');
            $table->dropConstrainedForeignId('packaging_transaction_code_id');
            $table->dropConstrainedForeignId('rounding_transaction_code_id');
            $table->dropConstrainedForeignId('cash_variance_transaction_code_id');
            $table->dropColumn([
                'native_pos_enabled', 'operation_mode', 'close_sales_time', 'sst_registration_number', 'rounding_mode', 'rounding_policy',
                'room_charge_limit', 'require_room_charge_signature', 'require_meal_redemption_confirmation', 'require_reprint_pin',
                'require_dual_control', 'print_receipt_after_payment', 'print_drawer_slip', 'require_clock_in', 'dynamic_qr_single_use',
                'dynamic_qr_ttl_minutes', 'allow_anonymous_qr', 'allow_qr_customer_remark', 'qr_ignore_stock',
            ]);
        });
    }
};
