<?php

namespace Database\Seeders;

use App\Models\Amenity;
use Illuminate\Database\Seeder;

class AmenitySeeder extends Seeder
{
    /**
     * Seed the starting amenity list from the wastays list (`data/amenities.json`). Safe to run again: each row is
     * matched on its type and name, and an existing row keeps the position and active state an admin gave it.
     */
    public function run(): void
    {
        /** @var list<array{name: string, type: string, category: string, icon: string|null}> $rows */
        $rows = json_decode((string) file_get_contents(__DIR__.'/data/amenities.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($rows as $index => $row) {
            Amenity::query()->firstOrCreate(
                ['type' => $row['type'], 'name' => $row['name']],
                ['category' => $row['category'], 'icon' => $row['icon'], 'position' => $index + 1],
            );
        }
    }
}
