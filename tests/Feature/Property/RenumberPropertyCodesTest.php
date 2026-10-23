<?php

use App\Models\AccountingSync;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

function renumberPropertyCodes(): void
{
    (require database_path('migrations/2026_10_23_054043_renumber_property_codes_to_four_digits.php'))->up();
}

test('renumbering gives each prefix 4-digit codes from 1101 in creation order and resets the counters', function () {
    $second = Property::factory()->create(['code' => 'ES10142', 'created_at' => now()->subDays(1)]);
    $first = Property::factory()->create(['code' => 'ES10101', 'created_at' => now()->subDays(5)]);
    $partnerOwned = Property::factory()->create(['code' => 'AS10150', 'created_at' => now()->subDays(3)]);
    DB::table('property_code_counters')->updateOrInsert(['prefix' => 'ES'], ['last_number' => 10142]);
    DB::table('property_code_counters')->updateOrInsert(['prefix' => 'AS'], ['last_number' => 10150]);
    DB::table('property_code_counters')->insert(['prefix' => 'KP', 'last_number' => 10100]);

    renumberPropertyCodes();

    expect($first->refresh()->code)->toBe('ES1101')
        ->and($second->refresh()->code)->toBe('ES1102')
        ->and($partnerOwned->refresh()->code)->toBe('AS1101')
        ->and(DB::table('property_code_counters')->pluck('last_number', 'prefix')->all())->toEqual(['KP' => 1100, 'ES' => 1102, 'AS' => 1101]);
});

test('renumbering stops when a journal was already pushed to an accounting provider', function () {
    $property = Property::factory()->create(['code' => 'ES10142']);
    AccountingSync::factory()->create(['property_id' => $property->id, 'pushed_at' => now()]);

    expect(fn () => renumberPropertyCodes())->toThrow(RuntimeException::class, 'cannot be renumbered');
    expect($property->refresh()->code)->toBe('ES10142');
});
