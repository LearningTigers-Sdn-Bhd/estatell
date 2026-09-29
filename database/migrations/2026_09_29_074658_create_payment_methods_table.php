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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_code_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('type', 30);
            $table->string('surcharge_type', 20)->default('none');
            $table->decimal('surcharge_value', 12, 2)->nullable();
            $table->foreignId('surcharge_charge_item_id')->nullable()->constrained('charge_items')->restrictOnDelete();
            $table->boolean('requires_reference')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['property_id', 'active', 'position']);
        });

        DB::statement('CREATE UNIQUE INDEX payment_methods_property_name_unique ON payment_methods (property_id, lower(name))');
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_type_check CHECK (type IN ('cash', 'card', 'bank_transfer', 'e_wallet', 'ota_collected', 'direct_bill'))");
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_surcharge_type_check CHECK (surcharge_type IN ('none', 'fixed', 'percent'))");
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_surcharge_complete_check CHECK ((surcharge_type = 'none' AND surcharge_value IS NULL AND surcharge_charge_item_id IS NULL) OR (surcharge_type IN ('fixed', 'percent') AND surcharge_value IS NOT NULL AND surcharge_charge_item_id IS NOT NULL))");
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_surcharge_value_check CHECK (surcharge_value IS NULL OR (surcharge_value > 0 AND (surcharge_type <> 'percent' OR surcharge_value <= 100)))");
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_name_check CHECK (name = btrim(name) AND name <> '')");

        $this->backfillMalaysiaPresets();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }

    private function backfillMalaysiaPresets(): void
    {
        $now = now();
        $presets = [
            ['system_key' => 'cash_payment', 'type' => 'cash', 'requires_reference' => false, 'active' => true],
            ['system_key' => 'card_payment', 'type' => 'card', 'requires_reference' => true, 'active' => true],
            ['system_key' => 'bank_transfer_payment', 'type' => 'bank_transfer', 'requires_reference' => true, 'active' => true],
            ['system_key' => 'ewallet_payment', 'type' => 'e_wallet', 'requires_reference' => true, 'active' => true],
            ['system_key' => 'ota_collected_payment', 'type' => 'ota_collected', 'requires_reference' => false, 'active' => false],
            ['system_key' => 'direct_bill_payment', 'type' => 'direct_bill', 'requires_reference' => false, 'active' => false],
        ];

        DB::table('properties')
            ->where('country_code', 'MY')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($now, $presets): void {
                foreach ($properties as $property) {
                    $codes = DB::table('transaction_codes')
                        ->where('property_id', $property->id)
                        ->whereIn('system_key', array_column($presets, 'system_key'))
                        ->get()
                        ->keyBy('system_key');

                    if ($codes->count() !== count($presets)) {
                        continue;
                    }

                    foreach ($presets as $position => $preset) {
                        $code = $codes->get($preset['system_key']);

                        DB::table('payment_methods')->insert([
                            'property_id' => $property->id,
                            'transaction_code_id' => $code->id,
                            'name' => $code->name,
                            'type' => $preset['type'],
                            'surcharge_type' => 'none',
                            'surcharge_value' => null,
                            'surcharge_charge_item_id' => null,
                            'requires_reference' => $preset['requires_reference'],
                            'position' => $position + 1,
                            'active' => $preset['active'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }
};
