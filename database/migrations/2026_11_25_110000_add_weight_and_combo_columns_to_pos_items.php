<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menu power features of Native POS phase 2: an item sold by weight (price per unit of weight, such as RM 10.90 per 50 g)
     * and an item that is a combo of slots. See docs/34-native-pos/02-menu-catalog.md.
     */
    public function up(): void
    {
        Schema::table('pos_items', function (Blueprint $table) {
            $table->boolean('sold_by_weight')->default(false);
            $table->string('weight_unit', 20)->nullable();
            $table->decimal('weight_unit_divisor', 10, 3)->nullable();
            $table->boolean('is_combo')->default(false);
        });

        DB::statement('ALTER TABLE pos_items ADD CONSTRAINT pos_items_weight_check CHECK (sold_by_weight = false OR (weight_unit IS NOT NULL AND weight_unit_divisor > 0))');
        DB::statement('ALTER TABLE pos_items ADD CONSTRAINT pos_items_weight_or_combo_check CHECK (NOT (sold_by_weight AND is_combo))');
    }
};
