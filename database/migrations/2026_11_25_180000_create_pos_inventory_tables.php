<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 back-of-house stock: ingredients, recipes (bill of materials) for items and modifier options, an immutable stock
     * ledger per outlet, and stocktakes that compare each count with the ledger at the moment it was counted.
     * See docs/34-native-pos/07-inventory-and-recipes.md.
     */
    public function up(): void
    {
        Schema::create('pos_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('sku', 64)->nullable();
            $table->string('base_unit', 20);
            $table->string('purchase_unit', 20)->nullable();
            $table->decimal('purchase_to_base_ratio', 12, 4)->default(1);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('low_stock_threshold', 12, 3)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX pos_ingredients_name_unique ON pos_ingredients (property_id, lower(name))');
        DB::statement('ALTER TABLE pos_ingredients ADD CONSTRAINT pos_ingredients_ratio_check CHECK (purchase_to_base_ratio > 0)');

        Schema::create('pos_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_item_variant_id')->nullable()->constrained('pos_item_variants')->cascadeOnDelete();
            $table->foreignId('pos_modifier_option_id')->nullable()->constrained('pos_modifier_options')->cascadeOnDelete();
            $table->foreignId('pos_ingredient_id')->constrained('pos_ingredients')->restrictOnDelete();
            $table->decimal('quantity_in_base_unit', 12, 4);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE pos_recipes ADD CONSTRAINT pos_recipes_owner_check CHECK ((pos_item_variant_id IS NULL) <> (pos_modifier_option_id IS NULL))');
        DB::statement('ALTER TABLE pos_recipes ADD CONSTRAINT pos_recipes_quantity_check CHECK (quantity_in_base_unit > 0)');
        DB::statement('CREATE UNIQUE INDEX pos_recipes_variant_ingredient_unique ON pos_recipes (pos_item_variant_id, pos_ingredient_id) WHERE pos_item_variant_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX pos_recipes_option_ingredient_unique ON pos_recipes (pos_modifier_option_id, pos_ingredient_id) WHERE pos_modifier_option_id IS NOT NULL');

        Schema::create('pos_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->foreignId('pos_ingredient_id')->constrained('pos_ingredients')->restrictOnDelete();
            $table->string('kind', 30);
            $table->decimal('quantity_delta', 12, 4);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason', 200)->nullable();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['property_id', 'pos_outlet_id', 'pos_ingredient_id', 'created_at'], 'pos_stock_movements_ledger_index');
        });

        DB::statement("ALTER TABLE pos_stock_movements ADD CONSTRAINT pos_stock_movements_kind_check CHECK (kind IN ('sale', 'void_return', 'refund_return', 'purchase_receive', 'transfer_in', 'transfer_out', 'waste', 'stocktake_adjustment'))");
        DB::statement('ALTER TABLE pos_stock_movements ADD CONSTRAINT pos_stock_movements_delta_check CHECK (quantity_delta <> 0)');

        Schema::create('pos_stocktakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 10)->default('draft');
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE pos_stocktakes ADD CONSTRAINT pos_stocktakes_status_check CHECK (status IN ('draft', 'closed'))");

        Schema::create('pos_stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_stocktake_id')->constrained('pos_stocktakes')->restrictOnDelete();
            $table->foreignId('pos_ingredient_id')->constrained('pos_ingredients')->restrictOnDelete();
            $table->timestamp('counted_at');
            $table->decimal('counted_quantity', 12, 4);
            $table->decimal('expected_quantity', 12, 4)->nullable();
            $table->decimal('variance_quantity', 12, 4)->nullable();
            $table->decimal('variance_cost', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['pos_stocktake_id', 'pos_ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_stocktake_lines');
        Schema::dropIfExists('pos_stocktakes');
        Schema::dropIfExists('pos_stock_movements');
        Schema::dropIfExists('pos_recipes');
        Schema::dropIfExists('pos_ingredients');
    }
};
