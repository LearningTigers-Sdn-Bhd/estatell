<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATEGORIES_BEFORE = "'room', 'food_and_beverage', 'laundry', 'minibar', 'fee', 'tax', 'discount', 'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'deposit', 'refund', 'adjustment', 'other'";

    private const CATEGORIES_AFTER = "'room', 'food_and_beverage', 'laundry', 'minibar', 'transport', 'fee', 'tax', 'discount', 'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'deposit', 'refund', 'adjustment', 'other'";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replaceCategoryChecks(self::CATEGORIES_AFTER);
    }

    /**
     * Reverse the migrations. Fails while a Transport row exists.
     */
    public function down(): void
    {
        $this->replaceCategoryChecks(self::CATEGORIES_BEFORE);
    }

    /**
     * Charge item codes and folio routing rules both check the category list.
     */
    private function replaceCategoryChecks(string $categories): void
    {
        DB::statement('ALTER TABLE transaction_codes DROP CONSTRAINT transaction_codes_category_check');
        DB::statement("ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_category_check CHECK (category IN ({$categories}))");

        DB::statement('ALTER TABLE folio_routing_rules DROP CONSTRAINT folio_routing_rules_category_check');
        DB::statement("ALTER TABLE folio_routing_rules ADD CONSTRAINT folio_routing_rules_category_check CHECK (category IS NULL OR category IN ({$categories}))");
    }
};
