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
            $table->foreignId('transaction_code_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('default_amount', 12, 2)->nullable();
            $table->string('charging_unit', 30);
            $table->boolean('allow_override')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['property_id', 'active', 'position']);
        });

        DB::statement('CREATE UNIQUE INDEX charge_items_property_name_unique ON charge_items (property_id, lower(name))');
        DB::statement("ALTER TABLE charge_items ADD CONSTRAINT charge_items_charging_unit_check CHECK (charging_unit IN ('per_item', 'per_night', 'per_stay', 'per_guest_night', 'per_guest'))");
        DB::statement('ALTER TABLE charge_items ADD CONSTRAINT charge_items_default_amount_check CHECK (default_amount IS NULL OR default_amount > 0)');
        DB::statement('ALTER TABLE charge_items ADD CONSTRAINT charge_items_open_amount_override_check CHECK (default_amount IS NOT NULL OR allow_override)');
        DB::statement("ALTER TABLE charge_items ADD CONSTRAINT charge_items_name_check CHECK (name = btrim(name) AND name <> '')");
        DB::statement("ALTER TABLE charge_items ADD CONSTRAINT charge_items_description_check CHECK (description IS NULL OR (description = btrim(description) AND description <> ''))");

        $this->backfillMalaysiaStarterItems();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_items');
    }

    /**
     * Migration-local snapshot. Runtime seeding is owned by SeedChargeItems.
     */
    private function backfillMalaysiaStarterItems(): void
    {
        $now = now();
        $systemKeys = ['food_and_beverage_revenue', 'laundry_revenue', 'minibar_revenue', 'fee_revenue'];

        DB::table('properties')
            ->where('country_code', 'MY')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($now, $systemKeys): void {
                foreach ($properties as $property) {
                    $codes = DB::table('transaction_codes')
                        ->where('property_id', $property->id)
                        ->whereIn('system_key', $systemKeys)
                        ->get()
                        ->keyBy('system_key');

                    foreach ($systemKeys as $position => $systemKey) {
                        $code = $codes->get($systemKey);

                        if ($code === null) {
                            continue;
                        }

                        DB::table('charge_items')->insert([
                            'property_id' => $property->id,
                            'transaction_code_id' => $code->id,
                            'name' => $code->name,
                            'description' => null,
                            'default_amount' => null,
                            'charging_unit' => 'per_item',
                            'allow_override' => true,
                            'position' => $position + 1,
                            'active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }
};
