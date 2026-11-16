<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turns the BedRoomTypes feature on for every property that already sells beds: a room type sold by bed or a per bed
 * rate plan, archived ones included. The feature is off by default, so without this those properties would lose the
 * Sold by and Per bed choices and the bed routes. Rows go straight into Pennant's table with the same name and scope
 * the feature uses (`bed-room-types`, `property:{id}`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $propertyIds = DB::table('room_types')->where('sell_unit', 'bed')->distinct()->pluck('property_id')
            ->merge(DB::table('rate_plans')->where('pricing_basis', 'per_bed')->distinct()->pluck('property_id'))
            ->unique()
            ->values();

        $now = now();

        DB::table('features')->upsert(
            $propertyIds->map(fn (int $id): array => [
                'name' => 'bed-room-types',
                'scope' => "property:{$id}",
                'value' => 'true',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['name', 'scope'],
            ['value', 'updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('features')->where('name', 'bed-room-types')->delete();
    }
};
