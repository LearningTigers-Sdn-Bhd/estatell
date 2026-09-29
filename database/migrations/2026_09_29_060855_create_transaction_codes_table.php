<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migration-local snapshot. Runtime seeding is owned by SystemTransactionCode.
     *
     * @var list<array{system_key: string, code: string, name: string, kind: string, category: string}>
     */
    private const DEFAULTS = [
        ['system_key' => 'room_revenue', 'code' => 'ROOM', 'name' => 'Room revenue', 'kind' => 'charge', 'category' => 'room'],
        ['system_key' => 'no_show_revenue', 'code' => 'NO_SHOW', 'name' => 'No-show revenue', 'kind' => 'charge', 'category' => 'room'],
        ['system_key' => 'cancellation_revenue', 'code' => 'CANCEL', 'name' => 'Cancellation revenue', 'kind' => 'charge', 'category' => 'room'],
        ['system_key' => 'late_checkout_revenue', 'code' => 'LATE_CHECKOUT', 'name' => 'Late checkout revenue', 'kind' => 'charge', 'category' => 'room'],
        ['system_key' => 'early_departure_revenue', 'code' => 'EARLY_DEPARTURE', 'name' => 'Early departure revenue', 'kind' => 'charge', 'category' => 'room'],
        ['system_key' => 'food_and_beverage_revenue', 'code' => 'FNB', 'name' => 'Food and beverage', 'kind' => 'charge', 'category' => 'food_and_beverage'],
        ['system_key' => 'laundry_revenue', 'code' => 'LAUNDRY', 'name' => 'Laundry', 'kind' => 'charge', 'category' => 'laundry'],
        ['system_key' => 'minibar_revenue', 'code' => 'MINIBAR', 'name' => 'Minibar', 'kind' => 'charge', 'category' => 'minibar'],
        ['system_key' => 'fee_revenue', 'code' => 'FEE', 'name' => 'General fee', 'kind' => 'charge', 'category' => 'fee'],
        ['system_key' => 'sst_tax', 'code' => 'SST', 'name' => 'SST', 'kind' => 'tax', 'category' => 'tax'],
        ['system_key' => 'tourism_tax', 'code' => 'TOURISM_TAX', 'name' => 'Tourism tax', 'kind' => 'tax', 'category' => 'tax'],
        ['system_key' => 'cash_payment', 'code' => 'CASH', 'name' => 'Cash', 'kind' => 'payment', 'category' => 'cash'],
        ['system_key' => 'card_payment', 'code' => 'CARD', 'name' => 'Card', 'kind' => 'payment', 'category' => 'card'],
        ['system_key' => 'bank_transfer_payment', 'code' => 'BANK_TRANSFER', 'name' => 'Bank transfer', 'kind' => 'payment', 'category' => 'bank_transfer'],
        ['system_key' => 'ewallet_payment', 'code' => 'E_WALLET', 'name' => 'E-wallet', 'kind' => 'payment', 'category' => 'e_wallet'],
        ['system_key' => 'ota_collected_payment', 'code' => 'OTA_COLLECTED', 'name' => 'OTA collected', 'kind' => 'payment', 'category' => 'ota_collected'],
        ['system_key' => 'direct_bill_payment', 'code' => 'DIRECT_BILL', 'name' => 'City ledger', 'kind' => 'payment', 'category' => 'direct_bill'],
        ['system_key' => 'security_deposit', 'code' => 'SECURITY_DEPOSIT', 'name' => 'Security deposit', 'kind' => 'payment', 'category' => 'deposit'],
        ['system_key' => 'refund', 'code' => 'REFUND', 'name' => 'Refund', 'kind' => 'payment', 'category' => 'refund'],
        ['system_key' => 'adjustment', 'code' => 'ADJUSTMENT', 'name' => 'Adjustment', 'kind' => 'adjustment', 'category' => 'adjustment'],
        ['system_key' => 'discount', 'code' => 'DISCOUNT', 'name' => 'Discount', 'kind' => 'adjustment', 'category' => 'discount'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transaction_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('system_key', 100)->nullable();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('kind', 20);
            $table->string('category', 30);
            $table->string('gl_account_code', 100)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'code']);
            $table->index(['property_id', 'kind', 'category', 'active']);
        });

        DB::statement('CREATE UNIQUE INDEX transaction_codes_system_key_unique ON transaction_codes (property_id, system_key) WHERE system_key IS NOT NULL');
        DB::statement("ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_kind_check CHECK (kind IN ('charge', 'payment', 'adjustment', 'tax'))");
        DB::statement("ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_category_check CHECK (category IN ('room', 'food_and_beverage', 'laundry', 'minibar', 'fee', 'tax', 'discount', 'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'deposit', 'refund', 'adjustment', 'other'))");
        DB::statement("ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_code_check CHECK (code = btrim(code) AND code <> '')");
        DB::statement("ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_name_check CHECK (name = btrim(name) AND name <> '')");
        DB::statement("ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_system_key_check CHECK (system_key IS NULL OR (system_key = btrim(system_key) AND system_key <> ''))");
        DB::statement("ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_gl_account_code_check CHECK (gl_account_code IS NULL OR (gl_account_code = btrim(gl_account_code) AND gl_account_code <> ''))");
        DB::statement('ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_system_identity_check CHECK ((is_system AND system_key IS NOT NULL) OR (NOT is_system AND system_key IS NULL))');

        $this->backfillExistingProperties();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_codes');
    }

    private function backfillExistingProperties(): void
    {
        $now = now();

        DB::table('properties')
            ->where('country_code', 'MY')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($now): void {
                $rows = [];

                foreach ($properties as $property) {
                    foreach (self::DEFAULTS as $default) {
                        $rows[] = [
                            ...$default,
                            'property_id' => $property->id,
                            'gl_account_code' => null,
                            'is_system' => true,
                            'active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                DB::table('transaction_codes')->insert($rows);
            });
    }
};
