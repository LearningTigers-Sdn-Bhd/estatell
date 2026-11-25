<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Combos of Native POS phase 2: a combo item has slots ("Choose Main", "Choose Drink"), and each slot lists the variants a
     * guest may pick, with an upcharge for the pricier ones. See docs/34-native-pos/02-menu-catalog.md.
     */
    public function up(): void
    {
        Schema::create('pos_combo_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_item_id')->constrained('pos_items')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('min_select')->default(1);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE pos_combo_slots ADD CONSTRAINT pos_combo_slots_select_check CHECK (min_select <= max_select AND max_select >= 1)');

        Schema::create('pos_combo_slot_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_combo_slot_id')->constrained('pos_combo_slots')->cascadeOnDelete();
            $table->foreignId('pos_item_variant_id')->constrained('pos_item_variants')->restrictOnDelete();
            $table->decimal('upcharge', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['pos_combo_slot_id', 'pos_item_variant_id']);
        });

        DB::statement('ALTER TABLE pos_combo_slot_options ADD CONSTRAINT pos_combo_slot_options_upcharge_check CHECK (upcharge >= 0)');
    }
};
