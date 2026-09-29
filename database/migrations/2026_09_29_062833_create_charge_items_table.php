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
        Schema::create('charge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_code_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('default_amount', 12, 2);
            $table->string('charging_unit', 30);
            $table->boolean('allow_override')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['property_id', 'active', 'position']);
        });

        DB::statement('CREATE UNIQUE INDEX charge_items_property_name_unique ON charge_items (property_id, lower(name))');
        DB::statement("ALTER TABLE charge_items ADD CONSTRAINT charge_items_charging_unit_check CHECK (charging_unit IN ('per_item', 'per_night', 'per_stay', 'per_guest_night', 'per_guest'))");
        DB::statement('ALTER TABLE charge_items ADD CONSTRAINT charge_items_default_amount_check CHECK (default_amount > 0)');
        DB::statement("ALTER TABLE charge_items ADD CONSTRAINT charge_items_name_check CHECK (name = btrim(name) AND name <> '')");
        DB::statement("ALTER TABLE charge_items ADD CONSTRAINT charge_items_description_check CHECK (description IS NULL OR (description = btrim(description) AND description <> ''))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_items');
    }
};
