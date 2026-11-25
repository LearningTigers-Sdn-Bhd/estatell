<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Native POS orders, lines, discounts, payments and the links to the folio lines a room charge posted. Money rows
     * use restrict foreign keys, never cascade: a sale is never silently removed. Lines copy names, prices and taxes at
     * order time. See docs/34-native-pos/03-floors-and-orders.md and 04-tax-checkout-and-pms.md.
     */
    public function up(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->boolean('no_post')->default(false);
        });

        Schema::create('pos_void_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('label', 100);
            $table->boolean('requires_manager_pin')->default(true);
            $table->boolean('default_return_stock')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX pos_void_reasons_label_unique ON pos_void_reasons (property_id, lower(label))');

        Schema::create('pos_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->foreignId('pos_shift_id')->nullable()->constrained('pos_shifts')->restrictOnDelete();
            $table->date('business_date')->nullable();
            $table->boolean('synced_late')->default(false);
            $table->uuid('client_uuid');
            $table->string('order_number', 40)->nullable();
            $table->string('order_type', 10)->default('sale');
            $table->foreignId('refund_for_order_id')->nullable()->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('parent_order_id')->nullable()->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('pos_dining_option_id')->constrained('pos_dining_options')->restrictOnDelete();
            $table->foreignId('reservation_unit_id')->nullable()->constrained('reservation_units')->restrictOnDelete();
            $table->unsignedSmallInteger('guest_count')->default(1);
            $table->string('source', 20)->default('terminal');
            $table->string('status', 20)->default('open');
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('service_charge_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('rounding_adjustment', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->boolean('settled_on_folio')->default(false);
            $table->decimal('folio_posted_amount', 12, 2)->default(0);
            $table->text('order_note')->nullable();
            $table->unsignedSmallInteger('prebill_print_count')->default(0);
            $table->string('receipt_claim_token', 40)->nullable()->unique();
            $table->unsignedBigInteger('e_invoice_submission_id')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('void_reason_id')->nullable()->constrained('pos_void_reasons')->restrictOnDelete();
            $table->string('void_reason_text', 255)->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('void_approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'client_uuid']);
            $table->index(['property_id', 'pos_outlet_id', 'business_date', 'status']);
            $table->index(['property_id', 'status']);
            $table->index(['pos_shift_id', 'status']);
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->foreign('e_invoice_submission_id')->references('id')->on('e_invoice_submissions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE pos_orders ADD CONSTRAINT pos_orders_folio_amount_check CHECK (folio_posted_amount >= 0 AND folio_posted_amount <= grand_total)');
        DB::statement("ALTER TABLE pos_orders ADD CONSTRAINT pos_orders_status_check CHECK (status IN ('open', 'settled', 'voided', 'merged'))");
        DB::statement("ALTER TABLE pos_orders ADD CONSTRAINT pos_orders_type_check CHECK (order_type IN ('sale', 'refund'))");
        DB::statement("ALTER TABLE pos_orders ADD CONSTRAINT pos_orders_source_check CHECK (source IN ('terminal', 'table_qr', 'room_qr'))");

        Schema::create('pos_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('pos_item_variant_id')->nullable()->constrained('pos_item_variants')->restrictOnDelete();
            $table->string('line_kind', 20)->default('item');
            $table->boolean('price_includes_tax')->default(false);
            $table->boolean('service_charge_exempt')->default(false);
            $table->foreignId('parent_combo_line_id')->nullable()->constrained('pos_order_lines')->restrictOnDelete();
            $table->foreignId('refund_of_order_line_id')->nullable()->constrained('pos_order_lines')->restrictOnDelete();
            $table->foreignId('transaction_code_id')->constrained('transaction_codes')->restrictOnDelete();
            $table->boolean('is_entitlement_covered')->default(false);
            $table->foreignId('meal_redemption_id')->nullable()->constrained('meal_redemptions')->restrictOnDelete();
            $table->string('item_name', 140);
            $table->string('variant_name', 80);
            $table->string('internal_name', 60)->nullable();
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('line_gross', 12, 2)->default(0);
            $table->string('discount_type', 20)->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->boolean('exclude_addons_from_discount')->default(false);
            $table->string('discount_reason', 160)->nullable();
            $table->foreignId('discount_approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->decimal('service_charge_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->unsignedSmallInteger('course_number')->default(1);
            $table->unsignedSmallInteger('seat_number')->nullable();
            $table->string('kitchen_status', 20)->default('draft');
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('void_reason_id')->nullable()->constrained('pos_void_reasons')->restrictOnDelete();
            $table->string('void_reason_text', 255)->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('void_approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('return_stock_on_void')->default(false);
            $table->timestamps();

            $table->index(['pos_order_id', 'kitchen_status']);
            $table->index('refund_of_order_line_id');
        });

        DB::statement("ALTER TABLE pos_order_lines ADD CONSTRAINT pos_order_lines_kind_check CHECK (line_kind IN ('item', 'combo_component', 'packaging_fee'))");
        DB::statement("ALTER TABLE pos_order_lines ADD CONSTRAINT pos_order_lines_kitchen_status_check CHECK (kitchen_status IN ('draft', 'held', 'fired', 'preparing', 'ready', 'served', 'voided'))");
        DB::statement('ALTER TABLE pos_order_lines ADD CONSTRAINT pos_order_lines_quantity_check CHECK (quantity > 0)');

        Schema::create('pos_order_line_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_order_line_id')->constrained('pos_order_lines')->restrictOnDelete();
            $table->foreignId('pos_modifier_option_id')->nullable()->constrained('pos_modifier_options')->nullOnDelete();
            $table->string('option_name', 100);
            $table->string('internal_name', 60)->nullable();
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->decimal('charged_price', 12, 2)->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);
        });

        Schema::create('pos_order_line_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_order_line_id')->constrained('pos_order_lines')->restrictOnDelete();
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            $table->foreignId('transaction_code_id')->constrained('transaction_codes')->restrictOnDelete();
            $table->string('tax_code', 20);
            $table->string('tax_name', 100);
            $table->string('lhdn_tax_type', 4)->nullable();
            $table->string('rate_type', 10);
            $table->decimal('tax_rate', 5, 2);
            $table->boolean('is_inclusive');
            $table->decimal('taxable_base', 12, 2);
            $table->decimal('tax_amount', 12, 2);
        });

        Schema::create('pos_order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
            $table->string('name', 100);
            $table->string('discount_type', 20);
            $table->decimal('value', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 160)->nullable();
            $table->foreignId('applied_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('pos_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->foreignId('refund_of_payment_id')->nullable()->constrained('pos_order_payments')->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->string('direction', 3)->default('in');
            $table->string('tender_type', 20);
            $table->string('reconciliation_status', 25)->default('verified');
            $table->decimal('amount', 12, 2);
            $table->decimal('tendered_amount', 12, 2)->nullable();
            $table->decimal('change_amount', 12, 2)->nullable();
            $table->decimal('rounding_adjustment', 12, 2)->default(0);
            $table->foreignId('reservation_unit_id')->nullable()->constrained('reservation_units')->restrictOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained('folios')->restrictOnDelete();
            $table->string('reference', 120)->nullable();
            $table->string('status', 20)->default('captured');
            $table->foreignId('replaced_by_payment_id')->nullable()->constrained('pos_order_payments')->restrictOnDelete();
            $table->foreignId('acted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['property_id', 'idempotency_key']);
            $table->index(['property_id', 'pos_shift_id', 'direction', 'tender_type', 'status']);
            $table->index(['pos_order_id', 'status']);
            $table->index('refund_of_payment_id');
        });

        DB::statement('ALTER TABLE pos_order_payments ADD CONSTRAINT pos_order_payments_amount_check CHECK (amount >= 0)');
        DB::statement("ALTER TABLE pos_order_payments ADD CONSTRAINT pos_order_payments_direction_check CHECK (direction IN ('in', 'out'))");
        DB::statement("ALTER TABLE pos_order_payments ADD CONSTRAINT pos_order_payments_status_check CHECK (status IN ('captured', 'replaced', 'voided'))");
        DB::statement("ALTER TABLE pos_order_payments ADD CONSTRAINT pos_order_payments_tender_check CHECK (tender_type IN ('cash', 'card', 'e_wallet', 'duitnow_qr', 'payment_voucher', 'room_charge'))");
        DB::statement("ALTER TABLE pos_order_payments ADD CONSTRAINT pos_order_payments_method_check CHECK ((tender_type = 'room_charge') = (payment_method_id IS NULL))");

        Schema::create('pos_order_folio_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('pos_order_payment_id')->constrained('pos_order_payments')->restrictOnDelete();
            $table->foreignId('folio_id')->constrained('folios')->restrictOnDelete();
            $table->foreignId('folio_line_id')->constrained('folio_lines')->restrictOnDelete();
            $table->foreignId('transaction_code_id')->constrained('transaction_codes')->restrictOnDelete();
            $table->decimal('net_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('gross_amount', 12, 2);
            $table->timestamps();

            $table->unique(['pos_order_payment_id', 'folio_line_id']);
            $table->unique('folio_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_folio_links');
        Schema::dropIfExists('pos_order_payments');
        Schema::dropIfExists('pos_order_discounts');
        Schema::dropIfExists('pos_order_line_taxes');
        Schema::dropIfExists('pos_order_line_modifiers');
        Schema::dropIfExists('pos_order_lines');
        Schema::dropIfExists('pos_orders');
        Schema::dropIfExists('pos_void_reasons');

        Schema::table('reservation_units', function (Blueprint $table) {
            $table->dropColumn('no_post');
        });
    }
};
