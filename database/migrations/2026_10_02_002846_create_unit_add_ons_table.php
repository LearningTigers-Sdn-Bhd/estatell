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
        Schema::create('unit_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount', 12, 2);
            $table->date('service_date')->nullable();
            $table->timestamps();

            $table->index('reservation_unit_id');
            $table->index('charge_item_id');
            $table->index('service_date');
        });

        DB::statement('ALTER TABLE unit_add_ons ADD CONSTRAINT unit_add_ons_quantity_check CHECK (quantity >= 1)');
        DB::statement('ALTER TABLE unit_add_ons ADD CONSTRAINT unit_add_ons_unit_amount_check CHECK (unit_amount >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_add_ons');
    }
};
