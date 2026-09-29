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
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_code_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->decimal('value', 12, 2)->nullable();
            $table->boolean('allow_override')->default(false);
            $table->string('scope', 20);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['property_id', 'active', 'position']);
        });

        Schema::create('discount_transaction_code', function (Blueprint $table) {
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_code_id')->constrained()->restrictOnDelete();

            $table->primary(['discount_id', 'transaction_code_id']);
        });

        DB::statement('CREATE UNIQUE INDEX discounts_property_name_unique ON discounts (property_id, lower(name))');
        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_type_check CHECK (type IN ('percent', 'fixed', 'open'))");
        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_scope_check CHECK (scope IN ('room_only', 'all_charges', 'selected'))");
        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_value_check CHECK (
            (type = 'percent' AND value BETWEEN 1 AND 100 AND NOT allow_override)
            OR (type = 'fixed' AND value > 0)
            OR (type = 'open' AND value IS NULL AND allow_override)
        )");
        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_name_check CHECK (name = btrim(name) AND name <> '')");

        $this->backfillMalaysiaStarterDiscount();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_transaction_code');
        Schema::dropIfExists('discounts');
    }

    /**
     * Migration-local snapshot. Runtime seeding is owned by SeedDiscounts.
     */
    private function backfillMalaysiaStarterDiscount(): void
    {
        $now = now();

        DB::table('properties')
            ->where('country_code', 'MY')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($now): void {
                foreach ($properties as $property) {
                    $code = DB::table('transaction_codes')
                        ->where('property_id', $property->id)
                        ->where('system_key', 'discount')
                        ->first();

                    if ($code === null) {
                        continue;
                    }

                    DB::table('discounts')->insert([
                        'property_id' => $property->id,
                        'transaction_code_id' => $code->id,
                        'name' => $code->name,
                        'type' => 'open',
                        'value' => null,
                        'allow_override' => true,
                        'scope' => 'all_charges',
                        'position' => 1,
                        'active' => $code->active,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }
};
