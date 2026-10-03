<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('folio_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category', 30)->nullable();
            $table->foreignId('transaction_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('target_folio_id')->constrained('folios')->restrictOnDelete();
            $table->string('source', 20);
            $table->timestamps();

            $table->index('reservation_unit_id');
            $table->index('transaction_code_id');
            $table->index('target_folio_id');
        });

        DB::statement("ALTER TABLE folio_routing_rules ADD CONSTRAINT folio_routing_rules_category_check CHECK (category IS NULL OR category IN ('room', 'food_and_beverage', 'laundry', 'minibar', 'fee', 'tax', 'discount', 'cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill', 'deposit', 'refund', 'adjustment', 'other'))");
        DB::statement("ALTER TABLE folio_routing_rules ADD CONSTRAINT folio_routing_rules_source_check CHECK (source IN ('billing_mode', 'manual'))");
        DB::statement('CREATE UNIQUE INDEX folio_routing_rules_selector_unique ON folio_routing_rules (reservation_id, reservation_unit_id, category, transaction_code_id) NULLS NOT DISTINCT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('folio_routing_rules');
    }
};
