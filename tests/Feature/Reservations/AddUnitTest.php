<?php

use App\Actions\Reservations\AddUnit;
use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\NewUnitData;
use App\Enums\ChargingUnit;
use App\Enums\DiscountScope;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Discount;
use App\Models\PackagePrice;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Models\RatePlanStayDiscount;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Rates\RateQuote;
use App\Services\Reservations\BookingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A property with settings, a PerRoom plan linked to a room type with rooms, and 100.00 rates for 8 nights from the
 * returned arrival date (30 days from now).
 *
 * @param  array<string, mixed>  $reservationAttributes
 * @return array{property: Property, plan: RatePlan, roomType: RoomType, reservation: Reservation, user: User, arrival: CarbonImmutable}
 */
function unitSetup(int $rooms = 2, array $reservationAttributes = []): array
{
    $property = Property::factory()->create();
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count($rooms)->for($property)->create(['room_type_id' => $roomType->id]);
    $plan->roomTypes()->attach($roomType);
    $arrival = CarbonImmutable::now()->addDays(30)->startOfDay();

    for ($night = 0; $night < 8; $night++) {
        Rate::factory()->create([
            'rate_plan_id' => $plan->id,
            'room_type_id' => $roomType->id,
            'date' => $arrival->addDays($night)->toDateString(),
            'amount' => '100.00',
        ]);
    }

    return [
        'property' => $property,
        'plan' => $plan,
        'roomType' => $roomType,
        'reservation' => Reservation::factory()->create(['property_id' => $property->id, ...$reservationAttributes]),
        'user' => User::factory()->for($property)->create(),
        'arrival' => $arrival,
    ];
}

/**
 * @param  array{roomType: RoomType, plan: RatePlan, arrival: CarbonImmutable}  $setup
 * @param  array<string, mixed>  $overrides
 */
function unitData(array $setup, int $nights = 3, array $overrides = []): NewUnitData
{
    return NewUnitData::from([
        'roomTypeId' => $setup['roomType']->id,
        'ratePlanId' => $setup['plan']->id,
        'arrivalDate' => $setup['arrival']->toDateString(),
        'departureDate' => $setup['arrival']->addDays($nights)->toDateString(),
        'adults' => 2,
        ...$overrides,
    ]);
}

/**
 * @param  array{reservation: Reservation, user: User}  $setup
 */
function addUnit(array $setup, NewUnitData $data, int $lineIndex = 0, bool $fromChannel = false): ReservationResult
{
    return app(AddUnit::class)($setup['user'], $setup['reservation'], $data, $lineIndex, $fromChannel);
}

function expectNothingWritten(): void
{
    expect(ReservationUnit::query()->count())->toBe(0)
        ->and(UnitNight::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
}

test('it adds a unit with a night for each date at the quoted amounts', function () {
    $setup = unitSetup();

    $result = addUnit($setup, unitData($setup, 3, ['childAges' => [], 'lateArrivalExpected' => true]));

    $unit = ReservationUnit::query()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($result->reservation->is($setup['reservation']))->toBeTrue()
        ->and($result->refusal)->toBeNull()
        ->and($unit->property_id)->toBe($setup['property']->id)
        ->and($unit->reservation_id)->toBe($setup['reservation']->id)
        ->and($unit->adults)->toBe(2)
        ->and($unit->children)->toBe(0)
        ->and($unit->late_arrival_expected)->toBeTrue()
        ->and($unit->status->value)->toBe('confirmed')
        ->and($unit->arrival_date->toDateString())->toBe($setup['arrival']->toDateString())
        ->and($unit->nights()->orderBy('date')->get()->pluck('date')->map->toDateString()->all())->toBe([
            $setup['arrival']->toDateString(),
            $setup['arrival']->addDay()->toDateString(),
            $setup['arrival']->addDays(2)->toDateString(),
        ]);

    $night = $unit->nights()->orderBy('date')->first();

    expect($night->rate_plan_id)->toBe($setup['plan']->id)
        ->and($night->base_amount)->toBe('100.00')
        ->and($night->extra_amount)->toBe('0.00')
        ->and($night->discount_amount)->toBe('0.00')
        ->and($night->override_amount)->toBeNull()
        ->and($night->amount)->toBe('100.00');
});

test('it stores the child ages and counts the children', function () {
    $setup = unitSetup();
    $setup['plan']->update(['extra_child_amount' => '25.00']);
    PropertyAgeGroup::factory()->for($setup['property'])->create(['min_age' => 0, 'max_age' => 11]);

    addUnit($setup, unitData($setup, 1, ['childAges' => [3, 9]]));

    $unit = ReservationUnit::query()->sole();

    expect($unit->children)->toBe(2)
        ->and($unit->childAges()->orderBy('id')->pluck('age')->all())->toBe([3, 9])
        ->and($unit->nights()->sole()->extra_amount)->toBe('50.00')
        ->and($unit->nights()->sole()->amount)->toBe('150.00');
});

test('the long stay discount of the quote is stored on the nights', function () {
    $setup = unitSetup();
    RatePlanStayDiscount::factory()->for($setup['plan'])->create(['min_nights' => 3, 'value' => '10.00']);

    addUnit($setup, unitData($setup, 3));

    $night = UnitNight::query()->first();

    expect($night->discount_amount)->toBe('10.00')
        ->and($night->amount)->toBe('90.00');
});

test('a catalogue discount stores only its id', function () {
    $setup = unitSetup();
    $discount = Discount::factory()->for($setup['property'])->create();

    addUnit($setup, unitData($setup, 2, ['discountId' => $discount->id]));

    expect(ReservationUnit::query()->sole()->discount_id)->toBe($discount->id)
        ->and(UnitNight::query()->pluck('discount_amount')->unique()->all())->toBe(['0.00']);
});

test('it refuses a room type of another property', function () {
    $setup = unitSetup();
    $other = RoomType::factory()->create();

    $result = addUnit($setup, unitData($setup, 2, ['roomTypeId' => $other->id]), 4);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND)
        ->and($result->reservation)->toBeNull()
        ->and($result->lineIndex)->toBe(4);
    expectNothingWritten();
});

test('it refuses an archived room type', function () {
    $setup = unitSetup();
    $setup['roomType']->update(['archived_at' => now()]);

    expect(addUnit($setup, unitData($setup))->refusal)->toBe(ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND);
    expectNothingWritten();
});

test('it refuses a rate plan of another property', function () {
    $setup = unitSetup();
    $other = RatePlan::factory()->create();

    expect(addUnit($setup, unitData($setup, 2, ['ratePlanId' => $other->id]))->refusal)->toBe(ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND);
    expectNothingWritten();
});

test('it refuses a plan that is not linked to the room type', function () {
    $setup = unitSetup();
    $unlinked = RatePlan::factory()->for($setup['property'])->create();

    expect(addUnit($setup, unitData($setup, 2, ['ratePlanId' => $unlinked->id]))->refusal)->toBe(ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED);
    expectNothingWritten();
});

test('it refuses a departure on or before the arrival', function () {
    $setup = unitSetup();

    expect(addUnit($setup, unitData($setup, 0))->refusal)->toBe(ReservationResult::REFUSAL_INVALID_STAY)
        ->and(addUnit($setup, unitData($setup, -1))->refusal)->toBe(ReservationResult::REFUSAL_INVALID_STAY);
    expectNothingWritten();
});

test('it refuses an arrival before today when the property has no business date', function () {
    $setup = unitSetup();
    $data = unitData($setup, 1, ['arrivalDate' => now()->subDay()->toDateString(), 'departureDate' => now()->toDateString()]);

    expect(addUnit($setup, $data)->refusal)->toBe(ReservationResult::REFUSAL_ARRIVAL_IN_PAST);
    expectNothingWritten();
});

test('it compares the arrival with the current business date', function () {
    $setup = unitSetup();
    BusinessDate::factory()->for($setup['property'])->create(['date' => $setup['arrival']->toDateString()]);
    Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => $setup['arrival']->subDay()->toDateString(), 'amount' => '100.00']);

    $before = unitData($setup, 2, ['arrivalDate' => $setup['arrival']->subDay()->toDateString(), 'departureDate' => $setup['arrival']->addDay()->toDateString()]);

    expect(addUnit($setup, $before)->refusal)->toBe(ReservationResult::REFUSAL_ARRIVAL_IN_PAST)
        ->and(addUnit($setup, unitData($setup, 2))->isRefused())->toBeFalse();
});

test('a channel booking may arrive in the past', function () {
    $setup = unitSetup(reservationAttributes: ['source' => ReservationSource::Channel]);
    $arrival = now()->subDay();
    Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => $arrival->toDateString(), 'amount' => '100.00']);

    $result = addUnit($setup, unitData($setup, 1, ['arrivalDate' => $arrival->toDateString(), 'departureDate' => now()->toDateString()]), fromChannel: true);

    expect($result->isRefused())->toBeFalse();
});

test('it refuses zero adults', function () {
    $setup = unitSetup();

    expect(addUnit($setup, unitData($setup, 2, ['adults' => 0]))->refusal)->toBe(ReservationResult::REFUSAL_ADULTS_REQUIRED);
    expectNothingWritten();
});

test('it refuses a child age outside 0 to 17', function (int $age) {
    $setup = unitSetup();

    expect(addUnit($setup, unitData($setup, 2, ['childAges' => [5, $age]]))->refusal)->toBe(ReservationResult::REFUSAL_CHILD_AGE_INVALID);
    expectNothingWritten();
})->with([-1, 18]);

test('it refuses a discount that is not an active room only discount of the property', function (string $case) {
    $setup = unitSetup();
    $discount = match ($case) {
        'inactive' => Discount::factory()->for($setup['property'])->inactive()->create(),
        'all charges' => Discount::factory()->for($setup['property'])->create(['scope' => DiscountScope::AllCharges]),
        'other property' => Discount::factory()->create(),
    };

    expect(addUnit($setup, unitData($setup, 2, ['discountId' => $discount->id]))->refusal)->toBe(ReservationResult::REFUSAL_DISCOUNT_NOT_ALLOWED);
    expectNothingWritten();
})->with(['inactive', 'all charges', 'other property']);

test('it refuses a stay that breaks a restriction', function (array $restriction, int $nights) {
    $setup = unitSetup();
    Rate::query()->where('date', $setup['arrival']->toDateString())->update($restriction);

    $result = addUnit($setup, unitData($setup, $nights));

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_RESTRICTED);
    expectNothingWritten();
})->with([
    'stop sell' => [['stop_sell' => true], 3],
    'closed to arrival' => [['closed_to_arrival' => true], 3],
    'min stay' => [['min_stay' => 4], 3],
    'max stay' => [['max_stay' => 2], 3],
]);

test('it refuses a stay that ends on a closed to departure date', function () {
    $setup = unitSetup();
    Rate::query()->where('date', $setup['arrival']->addDays(3)->toDateString())->update(['closed_to_departure' => true]);

    expect(addUnit($setup, unitData($setup, 3))->refusal)->toBe(ReservationResult::REFUSAL_RESTRICTED);
});

/**
 * @param  list<string>  $permissions
 */
function unitStaffWith(Property $property, array $permissions): User
{
    $user = User::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Override '.implode(',', $permissions)]);
    $role->givePermissionTo($permissions);
    setPermissionsTeamId($property->id);
    $user->assignRole($role);

    return $user;
}

test('a restriction can be booked past with a reason and the permission, and the override is logged', function () {
    $setup = unitSetup();
    $setup['user'] = unitStaffWith($setup['property'], ['rates.override-restrictions']);
    Rate::query()->where('date', $setup['arrival']->toDateString())->update(['min_stay' => 5]);

    $result = addUnit($setup, unitData($setup, 2, ['restrictionOverrideReason' => 'Owner friend']));

    $activity = Activity::query()->where('event', 'reservation.unit_added')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and(ReservationUnit::query()->count())->toBe(1)
        ->and($activity->properties['restriction_override_reason'])->toBe('Owner friend')
        ->and($activity->properties['restriction_violations'])->toHaveCount(1);
});

test('the permission team is put back after the check', function () {
    $setup = unitSetup();
    $setup['user'] = unitStaffWith($setup['property'], ['rates.override-restrictions']);
    Rate::query()->where('date', $setup['arrival']->toDateString())->update(['min_stay' => 5]);
    setPermissionsTeamId(987654);

    addUnit($setup, unitData($setup, 2, ['restrictionOverrideReason' => 'Owner friend']));

    expect(getPermissionsTeamId())->toBe(987654);
});

test('a restriction override needs the permission', function () {
    $setup = unitSetup();
    $setup['user'] = unitStaffWith($setup['property'], ['rates.view']);
    Rate::query()->where('date', $setup['arrival']->toDateString())->update(['stop_sell' => true]);

    $result = addUnit($setup, unitData($setup, 2, ['restrictionOverrideReason' => 'Please']));

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_RESTRICTION_OVERRIDE_NOT_ALLOWED);
    expectNothingWritten();
});

test('a restriction override reason is ignored and not logged when nothing is violated', function () {
    $setup = unitSetup();

    addUnit($setup, unitData($setup, 2, ['restrictionOverrideReason' => 'Just in case']));

    $activity = Activity::query()->where('event', 'reservation.unit_added')->sole();

    expect($activity->properties->has('restriction_override_reason'))->toBeFalse();
});

test('a channel booking skips the restriction check', function () {
    $setup = unitSetup(reservationAttributes: ['source' => ReservationSource::Channel]);
    Rate::query()->where('date', $setup['arrival']->toDateString())->update(['stop_sell' => true, 'min_stay' => 9]);

    expect(addUnit($setup, unitData($setup, 2), fromChannel: true)->isRefused())->toBeFalse();
});

test('it refuses a stay when a night is sold out and names the night', function () {
    $setup = unitSetup(rooms: 1);
    $first = addUnit($setup, unitData($setup, 2));

    $second = addUnit($setup, unitData($setup, 3, ['arrivalDate' => $setup['arrival']->addDay()->toDateString(), 'departureDate' => $setup['arrival']->addDays(4)->toDateString()]), 2);

    expect($first->isRefused())->toBeFalse()
        ->and($second->refusal)->toBe(ReservationResult::REFUSAL_NOT_AVAILABLE)
        ->and($second->lineIndex)->toBe(2)
        ->and($second->date->toDateString())->toBe($setup['arrival']->addDay()->toDateString())
        ->and(ReservationUnit::query()->count())->toBe(1);
});

test('availability ignores released units and other room types', function () {
    $setup = unitSetup(rooms: 1);
    $first = addUnit($setup, unitData($setup, 2));
    ReservationUnit::query()->update(['status' => 'cancelled']);

    expect(addUnit($setup, unitData($setup, 2))->isRefused())->toBeFalse();

    $otherType = RoomType::factory()->for($setup['property'])->create();
    Room::factory()->for($setup['property'])->create(['room_type_id' => $otherType->id]);
    $setup['plan']->roomTypes()->attach($otherType);
    Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $otherType->id, 'date' => $setup['arrival']->toDateString(), 'amount' => '80.00']);

    expect(addUnit($setup, unitData($setup, 1, ['roomTypeId' => $otherType->id]))->isRefused())->toBeFalse()
        ->and($first->isRefused())->toBeFalse();
});

test('a blocked room is not available', function () {
    $setup = unitSetup(rooms: 1);
    RoomBlock::factory()->create([
        'room_id' => Room::query()->sole()->id,
        'starts_on' => $setup['arrival']->toDateString(),
        'ends_on' => $setup['arrival']->addDays(5)->toDateString(),
    ]);

    expect(addUnit($setup, unitData($setup, 2))->refusal)->toBe(ReservationResult::REFUSAL_NOT_AVAILABLE);
});

test('a channel booking may overbook', function () {
    $setup = unitSetup(rooms: 1, reservationAttributes: ['source' => ReservationSource::Channel]);

    addUnit($setup, unitData($setup, 2), fromChannel: true);

    expect(addUnit($setup, unitData($setup, 2), fromChannel: true)->isRefused())->toBeFalse()
        ->and(ReservationUnit::query()->count())->toBe(2);
});

test('a channel-source reservation gets every check when staff add a unit', function () {
    $setup = unitSetup(rooms: 1, reservationAttributes: ['source' => ReservationSource::Channel]);
    $yesterday = now()->subDay();
    $inPast = unitData($setup, 1, ['arrivalDate' => $yesterday->toDateString(), 'departureDate' => now()->toDateString()]);
    Rate::query()->where('date', $setup['arrival']->toDateString())->update(['stop_sell' => true]);

    expect(addUnit($setup, $inPast)->refusal)->toBe(ReservationResult::REFUSAL_ARRIVAL_IN_PAST)
        ->and(addUnit($setup, unitData($setup, 2))->refusal)->toBe(ReservationResult::REFUSAL_RESTRICTED);

    Rate::query()->where('date', $setup['arrival']->toDateString())->update(['stop_sell' => false]);
    addUnit($setup, unitData($setup, 2));

    expect(addUnit($setup, unitData($setup, 2))->refusal)->toBe(ReservationResult::REFUSAL_NOT_AVAILABLE);
});

test('it refuses a Cancelled or Completed reservation before any other check', function (ReservationStatus $status) {
    $setup = unitSetup();
    $setup['reservation']->forceFill(['status' => $status])->save();

    $result = addUnit($setup, unitData($setup, 2, ['roomTypeId' => 999999, 'adults' => 0]));

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_RESERVATION_CLOSED)
        ->and($result->lineIndex)->toBe(0);
    expectNothingWritten();
    expect($setup['reservation']->refresh()->status)->toBe($status);
})->with([ReservationStatus::Cancelled, ReservationStatus::Completed]);

test('an InHouse or Confirmed reservation can take a unit', function (ReservationStatus $status) {
    $setup = unitSetup();
    $setup['reservation']->forceFill(['status' => $status])->save();

    expect(addUnit($setup, unitData($setup, 2))->isRefused())->toBeFalse();
})->with([ReservationStatus::Confirmed, ReservationStatus::InHouse]);

test('it reads the room type with a row lock before checking availability', function () {
    $setup = unitSetup();
    DB::enableQueryLog();

    addUnit($setup, unitData($setup, 2));

    $queries = collect(DB::getQueryLog())->pluck('query');
    $lock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "room_types"') && str_contains($sql, 'for update'));
    $availability = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "rooms"'));

    expect($lock)->not->toBeFalse()
        ->and($lock)->toBeLessThan($availability);
});

test('an override needs a reason', function () {
    $setup = unitSetup();

    expect(addUnit($setup, unitData($setup, 2, ['overrideTotal' => '150.00']))->refusal)->toBe(ReservationResult::REFUSAL_OVERRIDE_REASON_REQUIRED)
        ->and(addUnit($setup, unitData($setup, 2, ['overrideTotal' => '150.00', 'overrideReason' => '  ']))->refusal)->toBe(ReservationResult::REFUSAL_OVERRIDE_REASON_REQUIRED);
    expectNothingWritten();
});

test('a negative override is refused and zero is allowed', function () {
    $setup = unitSetup();

    expect(addUnit($setup, unitData($setup, 2, ['overrideTotal' => '-1.00', 'overrideReason' => 'Typo']))->refusal)->toBe(ReservationResult::REFUSAL_OVERRIDE_INVALID)
        ->and(addUnit($setup, unitData($setup, 2, ['overrideTotal' => 'abc', 'overrideReason' => 'Typo']))->refusal)->toBe(ReservationResult::REFUSAL_OVERRIDE_INVALID)
        ->and(addUnit($setup, unitData($setup, 2, ['overrideTotal' => '33.333', 'overrideReason' => 'Typo']))->refusal)->toBe(ReservationResult::REFUSAL_OVERRIDE_INVALID)
        ->and(addUnit($setup, unitData($setup, 2, ['overrideTotal' => '0', 'overrideReason' => 'Complimentary']))->isRefused())->toBeFalse()
        ->and(UnitNight::query()->pluck('amount')->unique()->all())->toBe(['0.00']);
});

test('an override is spread over the nights and the last night takes the remainder', function () {
    $setup = unitSetup();

    addUnit($setup, unitData($setup, 3, ['overrideTotal' => '100.00', 'overrideReason' => 'Loyal guest']));

    $nights = UnitNight::query()->orderBy('date')->get();

    expect($nights->pluck('override_amount')->all())->toBe(['33.33', '33.33', '33.34'])
        ->and($nights->pluck('amount')->all())->toBe(['33.33', '33.33', '33.34'])
        ->and($nights->pluck('base_amount')->unique()->all())->toBe(['100.00'])
        ->and($nights->sum(fn (UnitNight $night): float => (float) $night->amount))->toBe(100.0);

    $activity = Activity::query()->where('event', 'reservation.unit_added')->sole();

    expect($activity->properties['override_reason'])->toBe('Loyal guest')
        ->and($activity->properties['override_total'])->toBe('100.00');
});

test('a package override covers its inclusions and the booking preview matches the stored night amounts', function () {
    $setup = unitSetup();
    $setup['plan']->forceFill(['pricing_basis' => PricingBasis::Package, 'fixed_nights' => 3, 'package_unit' => PackageUnit::PerGuest])->save();
    PackagePrice::factory()->for($setup['plan'])->create(['room_type_id' => $setup['roomType']->id, 'arrival_from' => $setup['arrival']->toDateString(), 'arrival_to' => $setup['arrival']->toDateString(), 'amount' => '880.00']);
    RatePlanInclusion::factory()->for($setup['plan'])->create(['charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '100.00']);
    RatePlanInclusion::factory()->for($setup['plan'])->create(['charging_unit' => ChargingUnit::PerGuest, 'quantity' => 2, 'allocated_amount' => '180.00']);

    $quote = app(BookingQuote::class)->line($setup['property'], $setup['roomType']->id, $setup['plan']->id, $setup['arrival'], $setup['arrival']->addDays(3), 2, [], 1, overrideTotal: '1800.00');
    $result = addUnit($setup, unitData($setup, 3, ['overrideTotal' => '1800.00', 'overrideReason' => 'Agreed package price']));

    expect($result->isRefused())->toBeFalse()
        ->and(UnitNight::query()->orderBy('date')->pluck('override_amount')->all())->toBe(['1080.00', '360.00', '360.00'])
        ->and(UnitNight::query()->orderBy('date')->pluck('amount')->all())->toBe(['1080.00', '360.00', '360.00'])
        ->and(array_map(fn ($night): string => $night->amount, $quote->nights))->toBe(['1080.00', '360.00', '360.00']);
});

test('a package override below its inclusions is refused before writing and through the booking preview', function () {
    $setup = unitSetup();
    $setup['plan']->forceFill(['pricing_basis' => PricingBasis::Package, 'fixed_nights' => 3, 'package_unit' => PackageUnit::PerGuest])->save();
    PackagePrice::factory()->for($setup['plan'])->create(['room_type_id' => $setup['roomType']->id, 'arrival_from' => $setup['arrival']->toDateString(), 'arrival_to' => $setup['arrival']->toDateString(), 'amount' => '880.00']);
    RatePlanInclusion::factory()->for($setup['plan'])->create(['charging_unit' => ChargingUnit::PerGuest, 'allocated_amount' => '700.00']);

    $quote = app(BookingQuote::class)->line($setup['property'], $setup['roomType']->id, $setup['plan']->id, $setup['arrival'], $setup['arrival']->addDays(3), 2, [], 1, overrideTotal: '1000.00');
    $result = addUnit($setup, unitData($setup, 3, ['overrideTotal' => '1000.00', 'overrideReason' => 'Agreed package price']));

    expect($result->refusal)->toBe(RateQuote::REFUSAL_PACKAGE_BELOW_INCLUSIONS)
        ->and($quote->errors)->toBe(['form' => 'The package price is less than its inclusions.']);
    expectNothingWritten();
});

test('an override above the quote is allowed', function () {
    $setup = unitSetup();

    addUnit($setup, unitData($setup, 2, ['overrideTotal' => '1000.00', 'overrideReason' => 'Event']));

    expect(UnitNight::query()->pluck('amount')->all())->toBe(['500.00', '500.00']);
});

test('a refused quote is passed through with its night', function () {
    $setup = unitSetup();
    Rate::query()->where('date', $setup['arrival']->addDay()->toDateString())->update(['amount' => null]);

    $result = addUnit($setup, unitData($setup, 3));

    expect($result->refusal)->toBe('rate_missing')
        ->and($result->date->toDateString())->toBe($setup['arrival']->addDay()->toDateString());
    expectNothingWritten();
});

test('an archived plan is refused by the quote', function () {
    $setup = unitSetup();
    $setup['plan']->update(['archived_at' => now()]);

    expect(addUnit($setup, unitData($setup, 2))->refusal)->toBe('plan_archived');
});

test('it logs the unit once with the actor', function () {
    $setup = unitSetup();

    addUnit($setup, unitData($setup, 2));

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('reservation.unit_added')
        ->and($activity->causer_id)->toBe($setup['user']->id)
        ->and($activity->subject_id)->toBe($setup['reservation']->id)
        ->and($activity->properties['property_id'])->toBe($setup['property']->id);
});

test('it syncs the reservation status', function () {
    $setup = unitSetup();
    ReservationUnit::factory()->for($setup['reservation'])->checkedIn()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id]);

    addUnit($setup, unitData($setup, 2));

    expect($setup['reservation']->fresh()->status)->toBe(ReservationStatus::InHouse);
});

test('it ignores the beds of the line and adds exactly one unit', function () {
    $setup = unitSetup();

    addUnit($setup, unitData($setup, 2, ['beds' => 3]));

    expect(ReservationUnit::query()->count())->toBe(1);
});

test('it adds the add-ons of the line to the new unit', function () {
    $setup = unitSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '50.00']);

    addUnit($setup, unitData($setup, 2, ['addOns' => [['chargeItemId' => $item->id, 'quantity' => 2]]]));

    $unit = ReservationUnit::query()->sole();

    expect($unit->addOns()->sole()->quantity)->toBe(2)
        ->and(Activity::query()->where('event', 'reservation.add_on_added')->count())->toBe(1);
});

test('a refused add-on refuses the unit, rolls it back and names the line', function () {
    $setup = unitSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '50.00', 'allow_override' => false]);

    $result = addUnit($setup, unitData($setup, 2, ['addOns' => [
        ['chargeItemId' => $item->id],
        ['chargeItemId' => $item->id, 'unitAmount' => '1.00'],
    ]]), 4);

    expect($result->reservation)->toBeNull()
        ->and($result->refusal)->toBe('amount_override_not_allowed')
        ->and($result->lineIndex)->toBe(4)
        ->and(UnitAddOn::query()->count())->toBe(0);
    expectNothingWritten();
});

test('a unit with no times takes the property check-in and check-out times', function () {
    $setup = unitSetup();
    $setup['property']->settings->update(['check_in_time' => '14:00', 'check_out_time' => '12:30']);

    addUnit($setup, unitData($setup));

    $unit = ReservationUnit::query()->sole();

    expect(substr($unit->arrival_time, 0, 5))->toBe('14:00')
        ->and(substr($unit->departure_time, 0, 5))->toBe('12:30');
});

test('a unit with no times copies the times of the first unit of the reservation', function () {
    $setup = unitSetup();

    addUnit($setup, unitData($setup, 2, ['arrivalTime' => '18:30', 'departureTime' => '10:00']));
    addUnit($setup, unitData($setup));

    $second = ReservationUnit::query()->orderByDesc('id')->first();

    expect(substr($second->arrival_time, 0, 5))->toBe('18:30')
        ->and(substr($second->departure_time, 0, 5))->toBe('10:00');
});
