<?php

use App\Actions\Reservations\AddOnResult;
use App\Actions\Reservations\AddUnitAddOn;
use App\Data\Reservations\NewAddOnData;
use App\Enums\ChargingUnit;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A property with settings and one Confirmed unit of 2 adults and 1 child for three nights from the returned arrival
 * date (30 days from now), plus an active PerItem charge item of 50.00 that allows overrides.
 *
 * @param  array<string, mixed>  $unitAttributes
 * @param  array<string, mixed>  $itemAttributes
 * @return array{property: Property, user: User, unit: ReservationUnit, item: ChargeItem, arrival: CarbonImmutable}
 */
function addOnSetup(array $unitAttributes = [], array $itemAttributes = []): array
{
    $property = Property::factory()->create();
    PropertySetting::factory()->for($property)->create();
    $arrival = CarbonImmutable::now()->addDays(30)->startOfDay();
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->create([
        'reservation_id' => $reservation->id,
        'arrival_date' => $arrival->toDateString(),
        'departure_date' => $arrival->addDays(3)->toDateString(),
        'adults' => 2,
        'children' => 1,
        ...$unitAttributes,
    ]);
    $item = ChargeItem::factory()->create([
        'property_id' => $property->id,
        'default_amount' => '50.00',
        'charging_unit' => ChargingUnit::PerItem,
        'allow_override' => true,
        ...$itemAttributes,
    ]);

    return ['property' => $property, 'user' => User::factory()->for($property)->create(), 'unit' => $unit, 'item' => $item, 'arrival' => $arrival];
}

/**
 * @param  array{user: User, unit: ReservationUnit, item: ChargeItem}  $setup
 * @param  array<string, mixed>  $data
 */
function addOn(array $setup, array $data = []): AddOnResult
{
    return app(AddUnitAddOn::class)($setup['user'], $setup['unit'], NewAddOnData::from(['chargeItemId' => $setup['item']->id, ...$data]));
}

function expectNoAddOnWritten(): void
{
    expect(UnitAddOn::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
}

test('a per night item writes one row per night at quantity one and ignores the entered date and quantity', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerNight]);

    $result = addOn($setup, ['quantity' => 5, 'serviceDate' => $setup['arrival']->addYears(2)->toDateString()]);

    $rows = UnitAddOn::query()->orderBy('service_date')->get();

    expect($result->isRefused())->toBeFalse()
        ->and($result->addOns)->toHaveCount(3)
        ->and($rows->map(fn (UnitAddOn $row): string => $row->service_date->toDateString())->all())->toBe([
            $setup['arrival']->toDateString(),
            $setup['arrival']->addDay()->toDateString(),
            $setup['arrival']->addDays(2)->toDateString(),
        ])
        ->and($rows->pluck('quantity')->all())->toBe([1, 1, 1])
        ->and($rows->pluck('unit_amount')->unique()->all())->toBe(['50.00']);
});

test('a per guest night item has adults plus children on every night', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerGuestNight]);

    addOn($setup);

    expect(UnitAddOn::query()->pluck('quantity')->all())->toBe([3, 3, 3]);
});

test('a per item add-on has the entered quantity, one row and no date', function () {
    $setup = addOnSetup();

    addOn($setup, ['quantity' => 4]);

    $row = UnitAddOn::query()->sole();

    expect($row->quantity)->toBe(4)
        ->and($row->service_date)->toBeNull()
        ->and($row->reservation_unit_id)->toBe($setup['unit']->id);
});

test('a per item quantity defaults to one', function () {
    addOn(addOnSetup());

    expect(UnitAddOn::query()->sole()->quantity)->toBe(1);
});

test('a per stay add-on is one row of quantity one with the given date', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerStay]);

    addOn($setup, ['quantity' => 9, 'serviceDate' => $setup['arrival']->addDay()->toDateString()]);

    $row = UnitAddOn::query()->sole();

    expect($row->quantity)->toBe(1)
        ->and($row->service_date->toDateString())->toBe($setup['arrival']->addDay()->toDateString());
});

test('a per guest add-on is one row of adults plus children', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerGuest]);

    addOn($setup);

    expect(UnitAddOn::query()->sole()->quantity)->toBe(3);
});

test('nightly rows before the business date are not written', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerNight]);
    BusinessDate::factory()->for($setup['property'])->create(['date' => $setup['arrival']->addDay()->toDateString()]);

    addOn($setup);

    expect(UnitAddOn::query()->orderBy('service_date')->get()->map(fn (UnitAddOn $row): string => $row->service_date->toDateString())->all())->toBe([
        $setup['arrival']->addDay()->toDateString(),
        $setup['arrival']->addDays(2)->toDateString(),
    ]);
});

test('a nightly item with no night left is refused as in the past', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerNight]);
    BusinessDate::factory()->for($setup['property'])->create(['date' => $setup['arrival']->addDays(3)->toDateString()]);

    expect(addOn($setup)->refusal)->toBe(AddOnResult::REFUSAL_SERVICE_DATE_IN_PAST);
    expectNoAddOnWritten();
});

test('the price is copied from the item and a later price change does not touch it', function () {
    $setup = addOnSetup();

    addOn($setup);
    $setup['item']->update(['default_amount' => '80.00']);

    expect(UnitAddOn::query()->sole()->unit_amount)->toBe('50.00');
});

test('an own amount is stored when the item allows overrides', function () {
    $setup = addOnSetup();

    addOn($setup, ['unitAmount' => '12.5']);

    expect(UnitAddOn::query()->sole()->unit_amount)->toBe('12.50');
    expect(Activity::query()->sole()->properties['amount_overridden'])->toBeTrue();
});

test('an amount equal to the default is not an override', function (string $amount) {
    $setup = addOnSetup(itemAttributes: ['allow_override' => false]);

    expect(addOn($setup, ['unitAmount' => $amount])->isRefused())->toBeFalse()
        ->and(UnitAddOn::query()->sole()->unit_amount)->toBe('50.00');
})->with(['50', '50.00', '50.0']);

test('a different amount is refused when the item does not allow overrides', function () {
    $setup = addOnSetup(itemAttributes: ['allow_override' => false]);

    expect(addOn($setup, ['unitAmount' => '49.99'])->refusal)->toBe(AddOnResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED);
    expectNoAddOnWritten();
});

test('an item without a default amount needs one, and zero is allowed', function () {
    $setup = addOnSetup(itemAttributes: ['default_amount' => null, 'allow_override' => true]);

    expect(addOn($setup)->refusal)->toBe(AddOnResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED);
    expectNoAddOnWritten();

    addOn($setup, ['unitAmount' => '0']);

    expect(UnitAddOn::query()->sole()->unit_amount)->toBe('0.00');
});

test('a malformed, negative or three decimal amount is refused', function (string $amount) {
    $setup = addOnSetup();

    expect(addOn($setup, ['unitAmount' => $amount])->refusal)->toBe(AddOnResult::REFUSAL_AMOUNT_INVALID);
    expectNoAddOnWritten();
})->with(['-1', '12.345', 'abc', '', '1,5']);

test('it refuses an item of another property, an inactive item and a missing item', function (string $case) {
    $setup = addOnSetup();
    $itemId = match ($case) {
        'other property' => ChargeItem::factory()->create()->id,
        'inactive' => ChargeItem::factory()->inactive()->create(['property_id' => $setup['property']->id])->id,
        'missing' => 999999,
    };

    $result = app(AddUnitAddOn::class)($setup['user'], $setup['unit'], new NewAddOnData($itemId));

    expect($result->refusal)->toBe(AddOnResult::REFUSAL_CHARGE_ITEM_NOT_FOUND);
    expectNoAddOnWritten();
})->with(['other property', 'inactive', 'missing']);

test('only a Confirmed or CheckedIn unit is open', function (string $state, bool $open) {
    $setup = addOnSetup();
    $setup['unit']->delete();
    $setup['unit'] = ReservationUnit::factory()->{$state}()->create([
        'reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id])->id,
        'arrival_date' => $setup['arrival']->toDateString(),
        'departure_date' => $setup['arrival']->addDays(3)->toDateString(),
    ]);

    $result = addOn($setup);

    expect($result->isRefused())->toBe(! $open)
        ->and($result->refusal)->toBe($open ? null : AddOnResult::REFUSAL_UNIT_NOT_OPEN);
})->with([
    ['cancelled', false],
    ['noShow', false],
    ['checkedOut', false],
    ['checkedIn', true],
]);

test('the service date must lie from arrival to departure, both included', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerStay]);

    expect(addOn($setup, ['serviceDate' => $setup['arrival']->subDay()->toDateString()])->refusal)->toBe(AddOnResult::REFUSAL_SERVICE_DATE_OUTSIDE_STAY)
        ->and(addOn($setup, ['serviceDate' => $setup['arrival']->addDays(4)->toDateString()])->refusal)->toBe(AddOnResult::REFUSAL_SERVICE_DATE_OUTSIDE_STAY);
    expectNoAddOnWritten();

    expect(addOn($setup, ['serviceDate' => $setup['arrival']->toDateString()])->isRefused())->toBeFalse()
        ->and(addOn($setup, ['serviceDate' => $setup['arrival']->addDays(3)->toDateString()])->isRefused())->toBeFalse();
});

test('the service date may not be before the business date', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerStay]);
    BusinessDate::factory()->for($setup['property'])->create(['date' => $setup['arrival']->addDay()->toDateString()]);

    expect(addOn($setup, ['serviceDate' => $setup['arrival']->toDateString()])->refusal)->toBe(AddOnResult::REFUSAL_SERVICE_DATE_IN_PAST);
    expectNoAddOnWritten();

    expect(addOn($setup, ['serviceDate' => $setup['arrival']->addDay()->toDateString()])->isRefused())->toBeFalse();
});

test('the service date falls back to today in the property time zone without a business date', function () {
    $setup = addOnSetup(
        unitAttributes: ['arrival_date' => now()->subDays(2)->toDateString(), 'departure_date' => now()->addDays(2)->toDateString()],
        itemAttributes: ['charging_unit' => ChargingUnit::PerStay],
    );

    expect(addOn($setup, ['serviceDate' => now()->subDay()->toDateString()])->refusal)->toBe(AddOnResult::REFUSAL_SERVICE_DATE_IN_PAST);
});

test('the outside stay check comes before the past check', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerStay]);

    expect(addOn($setup, ['serviceDate' => now()->subYear()->toDateString()])->refusal)->toBe(AddOnResult::REFUSAL_SERVICE_DATE_OUTSIDE_STAY);
});

test('a nightly item ignores the date checks', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerNight]);

    expect(addOn($setup, ['serviceDate' => now()->subYear()->toDateString()])->isRefused())->toBeFalse();
});

test('a quantity from 1 to 999 is valid', function (int $quantity, bool $valid) {
    $setup = addOnSetup();

    $result = addOn($setup, ['quantity' => $quantity]);

    expect($result->isRefused())->toBe(! $valid)
        ->and($result->refusal)->toBe($valid ? null : AddOnResult::REFUSAL_QUANTITY_INVALID);
})->with([[0, false], [-1, false], [1, true], [999, true], [1000, false]]);

test('an undated add-on on a CheckedIn unit gets the business date', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerStay]);
    $setup['unit']->forceFill(['status' => 'checked_in', 'checked_in_at' => now()])->save();
    BusinessDate::factory()->for($setup['property'])->create(['date' => $setup['arrival']->addDay()->toDateString()]);

    addOn($setup);

    expect(UnitAddOn::query()->sole()->service_date->toDateString())->toBe($setup['arrival']->addDay()->toDateString());
});

test('an undated add-on on a Confirmed unit stays undated', function () {
    addOn(addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerStay]));

    expect(UnitAddOn::query()->sole()->service_date)->toBeNull();
});

test('the same item can be added twice', function () {
    $setup = addOnSetup();

    addOn($setup);
    addOn($setup);

    expect(UnitAddOn::query()->count())->toBe(2);
});

test('it logs once on the reservation with the actor', function () {
    $setup = addOnSetup(itemAttributes: ['charging_unit' => ChargingUnit::PerNight]);

    addOn($setup);

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('reservation.add_on_added')
        ->and($activity->subject_id)->toBe($setup['unit']->reservation_id)
        ->and($activity->causer_id)->toBe($setup['user']->id)
        ->and($activity->properties['rows'])->toBe(3)
        ->and($activity->properties['property_id'])->toBe($setup['property']->id);
});

test('the unit row is read with a lock', function () {
    $setup = addOnSetup();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    addOn($setup);

    expect(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "reservation_units"') && str_contains($sql, 'for update')))->toBeTrue();
});
