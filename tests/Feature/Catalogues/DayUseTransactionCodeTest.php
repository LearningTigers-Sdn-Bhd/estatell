<?php

use App\Actions\Catalogues\SeedTaxes;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Actions\Catalogues\UpdateDayUseTaxes;
use App\Actions\Catalogues\UpdateRoomRevenueTaxes;
use App\Actions\Rates\CreateRatePlan;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use App\Services\Property\CountryProfiles;
use Illuminate\Support\Facades\DB;

/** @return array{0: User, 1: Property} */
function dayUseTaxSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));
    app(SeedTaxes::class)($property, CountryProfiles::for('MY'));
    $property->taxes()->update(['enabled' => true]);
    $taxes = $property->taxes()->pluck('id')->all();
    app(UpdateRoomRevenueTaxes::class)($property, $owner, $taxes);

    return [$owner, $property];
}

function dayUseTaxIds(Property $property): array
{
    return $property->transactionCodes()->where('system_key', 'day_use_revenue')->sole()->applicableTaxes()->pluck('taxes.id')->sort()->values()->all();
}

test('seeding creates DAY_USE as a Room charge and is safe to run again', function () {
    $property = Property::factory()->create();
    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));
    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));

    $code = $property->transactionCodes()->where('system_key', 'day_use_revenue')->sole();

    expect($code->code)->toBe('DAY_USE')
        ->and($code->name)->toBe('Day use')
        ->and($code->kind)->toBe(TransactionKind::Charge)
        ->and($code->category)->toBe(TransactionCategory::Room)
        ->and($code->is_system)->toBeTrue()
        ->and(SystemTransactionCode::roomRevenueKeys())->not->toContain('day_use_revenue');
});

test('the backfill migration adds DAY_USE to properties that lack it and leaves the rest alone', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));
    $property->transactionCodes()->where('system_key', 'day_use_revenue')->delete();
    $before = $property->transactionCodes()->count();

    (include database_path('migrations/2026_10_11_110054_backfill_day_use_transaction_code.php'))->up();

    expect($property->transactionCodes()->count())->toBe($before + 1)
        ->and($property->transactionCodes()->where('system_key', 'day_use_revenue')->exists())->toBeTrue();
});

test('the first day use plan links the Taxes tab taxes except flat taxes, and later plans do not', function () {
    [$owner, $property] = dayUseTaxSetup();
    $sst = $property->taxes()->where('code', 'SST')->sole();
    $tourism = $property->taxes()->where('code', 'TTX')->sole();

    expect($tourism->rate_type)->toBe(TaxRateType::Flat)
        ->and(dayUseTaxIds($property))->toBe([]);

    app(CreateRatePlan::class)($property, $owner, ['name' => 'Day use', 'pricing_basis' => 'day_use', 'day_use_min_hours' => 2, 'day_use_max_hours' => 6]);

    expect(dayUseTaxIds($property))->toBe([$sst->id]);

    $property->transactionCodes()->where('system_key', 'day_use_revenue')->sole()->applicableTaxes()->sync([]);
    app(CreateRatePlan::class)($property, $owner, ['name' => 'Day use 2', 'pricing_basis' => 'day_use', 'day_use_min_hours' => 1, 'day_use_max_hours' => 3]);

    expect(dayUseTaxIds($property))->toBe([]);
});

test('the first day use plan keeps taxes already set on DAY_USE', function () {
    [$owner, $property] = dayUseTaxSetup();
    $tax = Tax::factory()->for($property)->create(['enabled' => false]);
    app(UpdateDayUseTaxes::class)($property, $owner, [$tax->id]);

    app(CreateRatePlan::class)($property, $owner, ['name' => 'Day use', 'pricing_basis' => 'day_use', 'day_use_min_hours' => 2, 'day_use_max_hours' => 6]);

    expect(dayUseTaxIds($property))->toBe([$tax->id]);
});

test('an archived day use plan still counts as the first', function () {
    [$owner, $property] = dayUseTaxSetup();
    $first = app(CreateRatePlan::class)($property, $owner, ['name' => 'Day use', 'pricing_basis' => 'day_use', 'day_use_min_hours' => 2, 'day_use_max_hours' => 6]);
    $first->forceFill(['archived_at' => now()])->save();
    $property->transactionCodes()->where('system_key', 'day_use_revenue')->sole()->applicableTaxes()->sync([]);

    app(CreateRatePlan::class)($property, $owner, ['name' => 'Day use again', 'pricing_basis' => 'day_use', 'day_use_min_hours' => 2, 'day_use_max_hours' => 6]);

    expect(dayUseTaxIds($property))->toBe([]);
});

test('a Taxes tab save does not change the DAY_USE taxes', function () {
    [$owner, $property] = dayUseTaxSetup();
    app(CreateRatePlan::class)($property, $owner, ['name' => 'Day use', 'pricing_basis' => 'day_use', 'day_use_min_hours' => 2, 'day_use_max_hours' => 6]);
    $before = dayUseTaxIds($property);
    $extra = Tax::factory()->for($property)->create();

    app(UpdateRoomRevenueTaxes::class)($property, $owner, [$extra->id]);

    expect(dayUseTaxIds($property))->toBe($before);
});

test('creating a day use plan before the DAY_USE code exists still works', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();

    $plan = app(CreateRatePlan::class)($property, $owner, ['name' => 'Day use', 'pricing_basis' => 'day_use', 'day_use_min_hours' => 2, 'day_use_max_hours' => 6]);

    expect($plan)->not->toBeNull()
        ->and(DB::table('transaction_codes')->where('system_key', 'day_use_revenue')->count())->toBe(0);
});
