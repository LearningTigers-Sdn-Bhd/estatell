<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATEGORIES_BEFORE = "'room', 'food_and_beverage', 'laundry', 'minibar', 'transport', 'fee', 'tax', 'discount', 'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'deposit', 'refund', 'adjustment', 'opening_balance', 'old_system', 'online', 'other'";

    private const CATEGORIES_AFTER = "'room', 'food_and_beverage', 'laundry', 'minibar', 'transport', 'facility', 'fee', 'tax', 'discount', 'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'deposit', 'refund', 'adjustment', 'opening_balance', 'old_system', 'online', 'other'";

    /**
     * Facilities staff can book, each priced by its own charge item, and the fixed sessions of Shared facilities.
     * See docs/33-facilities/02-facility-settings.md.
     */
    public function up(): void
    {
        $this->replaceCategoryChecks(self::CATEGORIES_AFTER);

        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('sharing', 20);
            $table->integer('capacity');
            $table->string('capacity_unit', 20);
            $table->foreignId('charge_item_id')->unique()->constrained()->restrictOnDelete();
            $table->string('rate_basis', 20);
            $table->integer('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX facilities_property_name_unique ON facilities (property_id, lower(name))');
        DB::statement('ALTER TABLE facilities ADD CONSTRAINT facilities_capacity_check CHECK (capacity > 0)');
        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_sharing_check CHECK (sharing IN ('private', 'shared'))");
        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_capacity_unit_check CHECK (capacity_unit IN ('pax', 'units'))");
        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_rate_basis_check CHECK (rate_basis IN ('day', 'session', 'hour', 'person'))");

        Schema::create('facility_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
            $table->unique(['facility_id', 'starts_at', 'ends_at']);
        });

        DB::statement('ALTER TABLE facility_sessions ADD CONSTRAINT facility_sessions_times_check CHECK (ends_at > starts_at)');
    }

    /**
     * Reverse the migrations. Fails while a Facility transaction code exists.
     */
    public function down(): void
    {
        Schema::dropIfExists('facility_sessions');
        Schema::dropIfExists('facilities');

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
