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
        Schema::create('meal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->time('starts_at', 0);
            $table->time('ends_at', 0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['property_id', 'active', 'starts_at']);
        });

        DB::statement('CREATE UNIQUE INDEX meal_periods_property_name_unique ON meal_periods (property_id, lower(name))');
        DB::statement('ALTER TABLE meal_periods ADD CONSTRAINT meal_periods_time_order_check CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE meal_periods ADD CONSTRAINT meal_periods_name_check CHECK (name = btrim(name) AND name <> '')");

        $this->backfillMalaysiaPresets();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_periods');
    }

    private function backfillMalaysiaPresets(): void
    {
        $now = now();
        $presets = [
            ['name' => 'Breakfast', 'starts_at' => '07:00', 'ends_at' => '10:00'],
            ['name' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '14:30'],
            ['name' => 'Dinner', 'starts_at' => '19:00', 'ends_at' => '22:00'],
        ];

        DB::table('properties')
            ->where('country_code', 'MY')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($now, $presets): void {
                foreach ($properties as $property) {
                    foreach ($presets as $preset) {
                        DB::table('meal_periods')->insert([
                            'property_id' => $property->id,
                            ...$preset,
                            'active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }
};
