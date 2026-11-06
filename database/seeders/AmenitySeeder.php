<?php

namespace Database\Seeders;

use App\Enums\AmenityType;
use App\Models\Amenity;
use App\Models\AmenityCategory;
use Illuminate\Database\Seeder;

class AmenitySeeder extends Seeder
{
    /**
     * Seed the starting amenity list from the wastays list (`data/amenities.json`). Safe to run again: each row is
     * matched on its type and name, and an existing row keeps the category, position and active state an admin gave it.
     * A category is only looked up for a new row, so a category an admin renamed does not come back under its old name.
     */
    public function run(): void
    {
        /** @var list<array{name: string, type: string, category: string, icon: string|null}> $rows */
        $rows = json_decode((string) file_get_contents(__DIR__.'/data/amenities.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($rows as $index => $row) {
            if (Amenity::query()->where('type', $row['type'])->where('name', $row['name'])->exists()) {
                continue;
            }

            $category = AmenityCategory::query()->firstOrCreate(
                ['type' => AmenityType::from($row['type']), 'name' => $row['category']],
                ['position' => ((int) AmenityCategory::query()->max('position')) + 1],
            );

            Amenity::query()->create([
                'type' => $row['type'],
                'name' => $row['name'],
                'amenity_category_id' => $category->id,
                'icon' => $row['icon'],
                'position' => $index + 1,
            ]);
        }
    }
}
