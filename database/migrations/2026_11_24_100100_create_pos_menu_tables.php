<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Native POS menu: outlet tenders, categories, items with variants, modifiers, per-outlet availability and dining
     * options. Columns that point at later-phase tables (kitchen stations, combos) are added by those phases.
     * See docs/34-native-pos/02-menu-catalog.md.
     */
    public function up(): void
    {
        Schema::create('pos_outlet_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('tender_type', 20);
            $table->boolean('pulse_drawer')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['pos_outlet_id', 'payment_method_id']);
        });

        DB::statement("ALTER TABLE pos_outlet_payment_methods ADD CONSTRAINT pos_outlet_payment_methods_tender_check CHECK (tender_type IN ('cash', 'card', 'e_wallet', 'duitnow_qr', 'payment_voucher'))");

        Schema::create('pos_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_code_id')->constrained('transaction_codes')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('internal_name', 60)->nullable();
            $table->string('color_token', 30)->default('category-1');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX pos_categories_name_unique ON pos_categories (property_id, lower(name))');

        Schema::create('pos_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_category_id')->constrained('pos_categories')->restrictOnDelete();
            $table->foreignId('transaction_code_id')->nullable()->constrained('transaction_codes')->restrictOnDelete();
            $table->foreignId('meal_period_id')->nullable()->constrained('meal_periods')->nullOnDelete();
            $table->string('name', 140);
            $table->string('internal_name', 60)->nullable();
            $table->text('description')->nullable();
            $table->string('sku_prefix', 40)->nullable();
            $table->boolean('price_includes_tax')->default(false);
            $table->boolean('service_charge_exempt')->default(false);
            $table->integer('shared_stock_count')->nullable();
            $table->integer('daily_shared_stock_reset')->nullable();
            $table->text('cooking_guide')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['property_id', 'pos_category_id', 'active']);
        });

        Schema::create('pos_item_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_item_id')->constrained('pos_items')->cascadeOnDelete();
            $table->string('name', 80)->default('Regular');
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('pricing_type', 20)->default('fixed');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX pos_item_variants_sku_unique ON pos_item_variants (property_id, sku) WHERE sku IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX pos_item_variants_barcode_unique ON pos_item_variants (property_id, barcode) WHERE barcode IS NOT NULL');
        DB::statement('ALTER TABLE pos_item_variants ADD CONSTRAINT pos_item_variants_price_check CHECK (price >= 0)');
        DB::statement("ALTER TABLE pos_item_variants ADD CONSTRAINT pos_item_variants_pricing_type_check CHECK (pricing_type IN ('fixed', 'variable'))");

        Schema::create('pos_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('min_select')->default(0);
            $table->unsignedSmallInteger('max_select')->nullable();
            $table->unsignedSmallInteger('max_per_option')->default(1);
            $table->unsignedSmallInteger('free_included_count')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE pos_modifier_groups ADD CONSTRAINT pos_modifier_groups_select_check CHECK (max_select IS NULL OR max_select >= min_select)');

        Schema::create('pos_modifier_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_modifier_group_id')->constrained('pos_modifier_groups')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('internal_name', 60)->nullable();
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->string('availability', 20)->default('all');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE pos_modifier_options ADD CONSTRAINT pos_modifier_options_availability_check CHECK (availability IN ('all', 'pos_only', 'none'))");

        Schema::create('pos_item_modifier_group', function (Blueprint $table) {
            $table->foreignId('pos_item_id')->constrained('pos_items')->cascadeOnDelete();
            $table->foreignId('pos_modifier_group_id')->constrained('pos_modifier_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['pos_item_id', 'pos_modifier_group_id']);
        });

        Schema::create('pos_outlet_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->cascadeOnDelete();
            $table->foreignId('pos_item_variant_id')->constrained('pos_item_variants')->cascadeOnDelete();
            $table->decimal('price_override', 12, 2)->nullable();
            $table->string('availability', 20)->default('all');
            $table->integer('stock_countdown')->nullable();
            $table->integer('daily_stock_reset')->nullable();
            $table->timestamps();

            $table->unique(['pos_outlet_id', 'pos_item_variant_id']);
        });

        DB::statement("ALTER TABLE pos_outlet_items ADD CONSTRAINT pos_outlet_items_availability_check CHECK (availability IN ('all', 'pos_only', 'none'))");
        DB::statement('ALTER TABLE pos_outlet_items ADD CONSTRAINT pos_outlet_items_price_check CHECK (price_override IS NULL OR price_override >= 0)');

        Schema::create('pos_dining_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 20);
            $table->boolean('apply_service_charge')->default(true);
            $table->decimal('service_charge_rate', 5, 2)->default(10.00);
            $table->boolean('service_charge_taxable')->default(false);
            $table->decimal('packaging_fee', 12, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX pos_dining_options_name_unique ON pos_dining_options (pos_outlet_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX pos_dining_options_default_unique ON pos_dining_options (pos_outlet_id) WHERE is_default');
        DB::statement("ALTER TABLE pos_dining_options ADD CONSTRAINT pos_dining_options_kind_check CHECK (kind IN ('dine_in', 'takeaway', 'room_service', 'poolside', 'minibar', 'delivery'))");
        DB::statement('ALTER TABLE pos_dining_options ADD CONSTRAINT pos_dining_options_rate_check CHECK (service_charge_rate BETWEEN 0 AND 100 AND packaging_fee >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_dining_options');
        Schema::dropIfExists('pos_outlet_items');
        Schema::dropIfExists('pos_item_modifier_group');
        Schema::dropIfExists('pos_modifier_options');
        Schema::dropIfExists('pos_modifier_groups');
        Schema::dropIfExists('pos_item_variants');
        Schema::dropIfExists('pos_items');
        Schema::dropIfExists('pos_categories');
        Schema::dropIfExists('pos_outlet_payment_methods');
    }
};
