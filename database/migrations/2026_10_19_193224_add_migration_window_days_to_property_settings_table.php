<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATEGORIES_BEFORE = "'room', 'food_and_beverage', 'laundry', 'minibar', 'transport', 'fee', 'tax', 'discount', 'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'deposit', 'refund', 'adjustment', 'other'";

    private const CATEGORIES_AFTER = "'room', 'food_and_beverage', 'laundry', 'minibar', 'transport', 'fee', 'tax', 'discount', 'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'deposit', 'refund', 'adjustment', 'opening_balance', 'old_system', 'other'";

    private const TYPES_BEFORE = "'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill'";

    private const TYPES_AFTER = "'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'old_system'";

    /**
     * Run the migrations. Adds the migration window setting, the two new transaction categories and the Old system
     * payment method type.
     */
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('migration_window_days')->default(60);
        });

        DB::statement('ALTER TABLE property_settings ADD CONSTRAINT property_settings_migration_window_days_check CHECK (migration_window_days BETWEEN 0 AND 365)');

        $this->replaceChecks(self::CATEGORIES_AFTER, self::TYPES_AFTER);
    }

    /**
     * Reverse the migrations. Fails while a row uses a new category or type.
     */
    public function down(): void
    {
        $this->replaceChecks(self::CATEGORIES_BEFORE, self::TYPES_BEFORE);

        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('migration_window_days');
        });
    }

    private function replaceChecks(string $categories, string $types): void
    {
        DB::statement('ALTER TABLE transaction_codes DROP CONSTRAINT transaction_codes_category_check');
        DB::statement("ALTER TABLE transaction_codes ADD CONSTRAINT transaction_codes_category_check CHECK (category IN ({$categories}))");

        DB::statement('ALTER TABLE folio_routing_rules DROP CONSTRAINT folio_routing_rules_category_check');
        DB::statement("ALTER TABLE folio_routing_rules ADD CONSTRAINT folio_routing_rules_category_check CHECK (category IS NULL OR category IN ({$categories}))");

        DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT payment_methods_type_check');
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_type_check CHECK (type IN ({$types}))");
    }
};
