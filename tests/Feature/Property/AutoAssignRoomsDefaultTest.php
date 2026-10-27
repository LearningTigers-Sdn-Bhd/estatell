<?php

use App\Models\Property;
use App\Models\PropertySetting;
use Illuminate\Support\Facades\DB;

test('the migration turns auto-assign on for every property and makes it the column default', function () {
    $migration = include database_path('migrations/2026_10_27_014736_turn_on_auto_assign_rooms_by_default.php');
    $migration->down();
    $off = PropertySetting::factory()->count(2)->create(['auto_assign_rooms' => false]);

    $migration->up();
    $newPropertyId = Property::factory()->create()->id;
    DB::table('property_settings')->insert(['property_id' => $newPropertyId, 'created_at' => now(), 'updated_at' => now()]);

    expect(PropertySetting::query()->whereKey($off->modelKeys())->pluck('auto_assign_rooms')->all())->toBe([true, true])
        ->and(DB::table('property_settings')->where('property_id', $newPropertyId)->value('auto_assign_rooms'))->toBeTrue();
});
