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
        Schema::create('property_age_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('label', 50);
            $table->unsignedSmallInteger('min_age');
            $table->unsignedSmallInteger('max_age');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE property_age_groups ADD CONSTRAINT property_age_groups_property_min_age_unique UNIQUE (property_id, min_age) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('CREATE UNIQUE INDEX property_age_groups_property_label_unique ON property_age_groups (property_id, lower(label))');
        DB::statement('ALTER TABLE property_age_groups ADD CONSTRAINT property_age_groups_range_check CHECK (max_age >= min_age AND max_age <= 17)');
        DB::statement("ALTER TABLE property_age_groups ADD CONSTRAINT property_age_groups_label_check CHECK (label = btrim(label) AND label <> '')");

        $this->backfillMalaysiaPresets();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_age_groups');
    }

    private function backfillMalaysiaPresets(): void
    {
        $now = now();
        $presets = [
            ['label' => 'Infant', 'min_age' => 0, 'max_age' => 3],
            ['label' => 'Child', 'min_age' => 4, 'max_age' => 11],
        ];

        DB::table('properties')
            ->where('country_code', 'MY')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($now, $presets): void {
                foreach ($properties as $property) {
                    foreach ($presets as $preset) {
                        DB::table('property_age_groups')->insert([
                            'property_id' => $property->id,
                            ...$preset,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }
};
