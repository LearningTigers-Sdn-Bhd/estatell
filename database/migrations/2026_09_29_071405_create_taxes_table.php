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
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_code_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 50);
            $table->string('rate_type', 20);
            $table->decimal('rate', 12, 2);
            $table->boolean('is_inclusive')->default(false);
            $table->boolean('foreign_guests_only')->default(false);
            $table->string('registration_number', 100)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->index(['property_id', 'enabled', 'name']);
        });

        Schema::create('tax_transaction_code', function (Blueprint $table) {
            $table->foreignId('tax_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_code_id')->constrained()->restrictOnDelete();

            $table->primary(['tax_id', 'transaction_code_id']);
        });

        DB::statement('CREATE UNIQUE INDEX taxes_property_name_unique ON taxes (property_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX taxes_property_code_unique ON taxes (property_id, lower(code))');
        DB::statement("ALTER TABLE taxes ADD CONSTRAINT taxes_rate_type_check CHECK (rate_type IN ('percent', 'flat'))");
        DB::statement("ALTER TABLE taxes ADD CONSTRAINT taxes_rate_check CHECK (rate > 0 AND (rate_type <> 'percent' OR rate <= 100))");
        DB::statement("ALTER TABLE taxes ADD CONSTRAINT taxes_name_check CHECK (name = btrim(name) AND name <> '')");
        DB::statement("ALTER TABLE taxes ADD CONSTRAINT taxes_code_check CHECK (code = upper(code) AND code ~ '^[A-Z0-9]+(_[A-Z0-9]+)*$')");
        DB::statement("ALTER TABLE taxes ADD CONSTRAINT taxes_registration_number_check CHECK (registration_number IS NULL OR (registration_number = btrim(registration_number) AND registration_number <> ''))");

        $this->backfillMalaysiaPresets();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_transaction_code');
        Schema::dropIfExists('taxes');
    }

    private function backfillMalaysiaPresets(): void
    {
        $now = now();

        DB::table('properties')
            ->where('country_code', 'MY')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($now): void {
                foreach ($properties as $property) {
                    $codes = DB::table('transaction_codes')
                        ->where('property_id', $property->id)
                        ->whereIn('system_key', ['room_revenue', 'sst_tax', 'tourism_tax'])
                        ->pluck('id', 'system_key');

                    if (! $codes->has(['room_revenue', 'sst_tax', 'tourism_tax'])) {
                        continue;
                    }

                    $presets = [
                        ['transaction_code_id' => $codes['sst_tax'], 'name' => 'Service Tax (SST)', 'code' => 'SST', 'rate_type' => 'percent', 'rate' => 8, 'foreign_guests_only' => false],
                        ['transaction_code_id' => $codes['tourism_tax'], 'name' => 'Tourism tax', 'code' => 'TTX', 'rate_type' => 'flat', 'rate' => 10, 'foreign_guests_only' => true],
                    ];

                    foreach ($presets as $preset) {
                        $taxId = DB::table('taxes')->insertGetId([
                            ...$preset,
                            'property_id' => $property->id,
                            'is_inclusive' => false,
                            'registration_number' => null,
                            'enabled' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        DB::table('tax_transaction_code')->insert([
                            'tax_id' => $taxId,
                            'transaction_code_id' => $codes['room_revenue'],
                        ]);
                    }
                }
            });
    }
};
