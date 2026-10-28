<?php

require_once __DIR__.'/../Support/DayUseSetup.php';

use App\Actions\Reservations\AddOnResult;
use App\Actions\Reservations\AddUnit;
use App\Actions\Reservations\AssignBed;
use App\Actions\Reservations\AssignmentResult;
use App\Actions\Reservations\AssignRoom;
use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\NewUnitData;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Enums\StayType;
use App\Models\Bed;
use App\Models\ChargeItem;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Availability\DayUseAvailability;
use App\Services\Rates\RateQuote;
use App\Services\Reservations\StayLabel;
use App\Services\Reservations\StayTotal;
use Spatie\Activitylog\Models\Activity;

test('books a day use unit with its times, hours, room and price and writes no nights', function () {
    $setup = dayUseSetup();

    $result = createDayUseReservation($setup, [dayUseLine($setup)]);
    $unit = ReservationUnit::query()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($unit->stay_type)->toBe(StayType::DayUse)
        ->and($unit->arrival_date->toDateString())->toBe($setup['date']->toDateString())
        ->and($unit->departure_date->toDateString())->toBe($setup['date']->toDateString())
        ->and(substr($unit->arrival_time, 0, 5))->toBe('10:00')
        ->and(substr($unit->departure_time, 0, 5))->toBe('14:00')
        ->and($unit->dayUse->hours)->toBe(4)
        ->and($unit->dayUse->room_id)->toBe($setup['rooms'][0]->id)
        ->and(substr($unit->dayUse->starts_at, 0, 5))->toBe('10:00')
        ->and(substr($unit->dayUse->ends_at, 0, 5))->toBe('14:00')
        ->and($unit->dayUse->amount)->toBe('100.00')
        ->and(UnitNight::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.unit_added')->sole()->properties->only(['stay_type', 'hours', 'room_id'])->all())
        ->toBe(['stay_type' => 'day_use', 'room_id' => $setup['rooms'][0]->id, 'hours' => 4]);
});

test('a second unit can take the other room or the same room after the first block', function () {
    $setup = dayUseSetup();

    $result = createDayUseReservation($setup, [
        dayUseLine($setup),
        dayUseLine($setup, ['roomId' => $setup['rooms'][1]->id, 'hours' => 2]),
        dayUseLine($setup, ['startTime' => '14:00', 'hours' => 2]),
    ]);

    expect($result->isRefused())->toBeFalse()->and(ReservationUnit::query()->count())->toBe(3);
});

function packagePlanOnRoomType(array $setup): RatePlan
{
    $plan = RatePlan::factory()->for($setup['property'])->create(['pricing_basis' => PricingBasis::Package, 'package_unit' => PackageUnit::PerRoom, 'fixed_nights' => 1]);
    $plan->roomTypes()->attach($setup['roomType']);

    return $plan;
}

/** @return array{0: array<string, mixed>, 1: string} The change to the line and the refusal it earns. */
function dayUseRefusalCase(string $case, array $setup): array
{
    $date = $setup['date'];

    return match ($case) {
        'no room' => [['roomId' => null], ReservationResult::REFUSAL_ROOM_REQUIRED],
        'a room of another type' => [['roomId' => Room::factory()->create()->id], AssignmentResult::REFUSAL_ROOM_NOT_FOUND],
        'a start off the half hour' => [['startTime' => '10:15'], ReservationResult::REFUSAL_START_TIME_INVALID],
        'no start' => [['startTime' => null], ReservationResult::REFUSAL_START_TIME_INVALID],
        'a block past midnight' => [['startTime' => '23:30', 'hours' => 2], ReservationResult::REFUSAL_START_TIME_INVALID],
        'hours not sold' => [['hours' => 8], RateQuote::REFUSAL_HOURS_NOT_SOLD],
        'no hours' => [['hours' => null], RateQuote::REFUSAL_HOURS_NOT_SOLD],
        'before the window' => [['startTime' => '07:00'], ReservationResult::REFUSAL_OUTSIDE_SOLD_BETWEEN],
        'ends after the window' => [['startTime' => '16:00', 'hours' => 3], ReservationResult::REFUSAL_OUTSIDE_SOLD_BETWEEN],
        'a date before the business date' => [['arrivalDate' => '2020-01-01', 'departureDate' => '2020-01-01'], ReservationResult::REFUSAL_ARRIVAL_IN_PAST],
        'different arrival and departure' => [['departureDate' => $date->addDay()->toDateString()], ReservationResult::REFUSAL_INVALID_STAY],
        'a date with no price' => [['arrivalDate' => $date->addDays(20)->toDateString(), 'departureDate' => $date->addDays(20)->toDateString()], RateQuote::REFUSAL_NO_HOUR_PRICE],
        'a per-night plan without an amount' => [['ratePlanId' => $setup['basic']->id], ReservationResult::REFUSAL_AMOUNT_REQUIRED],
        'a package plan' => [['ratePlanId' => packagePlanOnRoomType($setup)->id, 'overrideTotal' => '90.00'], RateQuote::REFUSAL_PLAN_NOT_DAY_USE],
        'a plan not on the room type' => [['ratePlanId' => RatePlan::factory()->for($setup['property'])->create()->id, 'overrideTotal' => '90.00'], ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED],
        'no adults' => [['adults' => 0], ReservationResult::REFUSAL_ADULTS_REQUIRED],
        'an invalid override total' => [['overrideTotal' => '-1.00'], ReservationResult::REFUSAL_OVERRIDE_INVALID],
        'a discount' => [['discountId' => 1], ReservationResult::REFUSAL_DISCOUNT_NOT_ALLOWED],
    };
}

test('a refusal writes nothing', function (string $case) {
    $setup = dayUseSetup();
    [$change, $refusal] = dayUseRefusalCase($case, $setup);

    $result = createDayUseReservation($setup, [dayUseLine($setup, $change)]);

    expect($result->refusal)->toBe($refusal)
        ->and($result->lineIndex)->toBe(0)
        ->and(Reservation::query()->count())->toBe(0)
        ->and(ReservationUnit::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'no room', 'a room of another type', 'a start off the half hour', 'no start', 'a block past midnight', 'hours not sold', 'no hours',
    'before the window', 'ends after the window', 'a date before the business date', 'different arrival and departure', 'a date with no price',
    'a per-night plan without an amount', 'a package plan', 'a plan not on the room type', 'no adults', 'an invalid override total', 'a discount',
]);

test('staff can override the block price with an optional logged reason', function (string $amount, ?string $reason) {
    $setup = dayUseSetup();

    $result = createDayUseReservation($setup, [dayUseLine($setup, ['overrideTotal' => $amount, 'overrideReason' => $reason])]);

    expect($result->isRefused())->toBeFalse();
    $block = $result->reservation->units()->sole()->dayUse;
    expect($block->amount)->toBe($amount)
        ->and($block->override_amount)->toBe($amount);
    $log = Activity::query()->where('event', 'reservation.unit_added')->sole();
    expect($log->properties['override_total'])->toBe($amount)
        ->and($log->properties['override_reason'])->toBe($reason === null ? null : trim($reason));
})->with([
    'reason supplied' => ['80.00', ' Regular guest '],
    'no reason' => ['80.00', null],
    'complimentary' => ['0.00', null],
]);

test('a per-night plan sells day use with an entered price outside the hours and window of the Day use plan', function (string $amount) {
    $setup = dayUseSetup();
    Rate::query()->whereDate('date', $setup['date'])->update(['stop_sell' => true]);

    $result = createDayUseReservation($setup, [dayUseLine($setup, ['ratePlanId' => $setup['basic']->id, 'startTime' => '02:30', 'hours' => 9, 'overrideTotal' => $amount])]);
    $unit = ReservationUnit::query()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($unit->rate_plan_id)->toBe($setup['basic']->id)
        ->and(substr($unit->arrival_time, 0, 5))->toBe('02:30')
        ->and(substr($unit->departure_time, 0, 5))->toBe('11:30')
        ->and($unit->dayUse->hours)->toBe(9)
        ->and($unit->dayUse->amount)->toBe($amount)
        ->and($unit->dayUse->override_amount)->toBe($amount)
        ->and(UnitNight::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.unit_added')->sole()->properties['override_reason'])->toBeNull();
})->with(['150.00', '0.00']);

test('an entered price block still ends before midnight and starts on the hour or half hour', function (string $startTime, int $hours, string $refusal) {
    $setup = dayUseSetup();

    $result = createDayUseReservation($setup, [dayUseLine($setup, ['ratePlanId' => $setup['basic']->id, 'startTime' => $startTime, 'hours' => $hours, 'overrideTotal' => '90.00'])]);

    expect($result->refusal)->toBe($refusal);
    $this->assertDatabaseCount('reservation_units', 0);
})->with([
    'past midnight' => ['20:00', 4, ReservationResult::REFUSAL_START_TIME_INVALID],
    'off the half hour' => ['10:15', 2, ReservationResult::REFUSAL_START_TIME_INVALID],
    'zero hours' => ['10:00', 0, RateQuote::REFUSAL_HOURS_NOT_SOLD],
]);

test('invalid override amounts write and log nothing', function (string $amount) {
    $setup = dayUseSetup();

    $result = createDayUseReservation($setup, [dayUseLine($setup, ['overrideTotal' => $amount])]);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_OVERRIDE_INVALID);
    $this->assertDatabaseCount('reservation_units', 0);
    $this->assertDatabaseCount('activity_log', 0);
})->with(['-1.00', '1.234', 'abc', '']);

test('an override cannot sell hours the plan does not price', function () {
    $setup = dayUseSetup();

    $result = createDayUseReservation($setup, [dayUseLine($setup, ['hours' => 8, 'overrideTotal' => '80.00'])]);

    expect($result->refusal)->toBe(RateQuote::REFUSAL_HOURS_NOT_SOLD);
    $this->assertDatabaseCount('reservation_units', 0);
});

test('an override still needs a price for the date and hours', function () {
    $setup = dayUseSetup();
    $date = $setup['date']->addDays(20)->toDateString();

    $result = createDayUseReservation($setup, [dayUseLine($setup, ['arrivalDate' => $date, 'departureDate' => $date, 'overrideTotal' => '80.00'])]);

    expect($result->refusal)->toBe(RateQuote::REFUSAL_NO_HOUR_PRICE);
    $this->assertDatabaseCount('reservation_units', 0);
});

test('a room busy for the hours is refused and the same room is free once the block ends', function () {
    $setup = dayUseSetup();
    createDayUseReservation($setup, [dayUseLine($setup)]);

    $clash = createDayUseReservation($setup, [dayUseLine($setup, ['startTime' => '12:00', 'hours' => 3])]);

    expect($clash->refusal)->toBe(ReservationResult::REFUSAL_NOT_AVAILABLE)
        ->and($clash->date->toDateString())->toBe($setup['date']->toDateString())
        ->and(Reservation::query()->count())->toBe(1);
});

test('the exclusion constraint is the last guard when the availability check is skipped', function () {
    $setup = dayUseSetup();
    createDayUseReservation($setup, [dayUseLine($setup)]);
    $mock = Mockery::mock(DayUseAvailability::class)->makePartial();
    $mock->shouldReceive('isFree')->andReturn(true);
    app()->instance(DayUseAvailability::class, $mock);

    $result = createDayUseReservation($setup, [dayUseLine($setup, ['startTime' => '12:00', 'hours' => 3])]);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_NOT_AVAILABLE)
        ->and(Reservation::query()->count())->toBe(1)
        ->and(ReservationUnit::query()->count())->toBe(1);
});

test('stop sell refuses a day use unless the actor may override and gives a reason', function () {
    $setup = dayUseSetup();
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', $setup['date'])->update(['stop_sell' => true]);

    expect(createDayUseReservation($setup, [dayUseLine($setup)])->refusal)->toBe(ReservationResult::REFUSAL_RESTRICTED)
        ->and(createDayUseReservation($setup, [dayUseLine($setup, ['restrictionOverrideReason' => 'VIP'])])->refusal)->toBe(ReservationResult::REFUSAL_RESTRICTION_OVERRIDE_NOT_ALLOWED);

    $owner = User::factory()->withRole(DefaultRoleEnum::Owner)->create(['property_id' => $setup['property']->id]);
    $setup['user'] = $owner;

    expect(createDayUseReservation($setup, [dayUseLine($setup, ['restrictionOverrideReason' => 'VIP'])])->isRefused())->toBeFalse()
        ->and(ReservationUnit::query()->sole()->dayUse->amount)->toBe('100.00');
});

test('a reservation is all overnight or all day use', function () {
    $setup = dayUseSetup();
    $overnight = ['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['basic']->id, 'arrivalDate' => $setup['date']->toDateString(), 'departureDate' => $setup['date']->addDay()->toDateString(), 'adults' => 2];

    expect(createDayUseReservation($setup, [dayUseLine($setup), $overnight])->refusal)->toBe(ReservationResult::REFUSAL_STAY_TYPES_MIXED)
        ->and(Reservation::query()->count())->toBe(0);

    $reservation = createDayUseReservation($setup, [dayUseLine($setup)])->reservation;

    expect(app(AddUnit::class)($setup['user'], $reservation, NewUnitData::from($overnight))->refusal)->toBe(ReservationResult::REFUSAL_STAY_TYPE_MISMATCH);

    $overnightReservation = createDayUseReservation($setup, [$overnight])->reservation;

    expect(app(AddUnit::class)($setup['user'], $overnightReservation, NewUnitData::from(dayUseLine($setup)))->refusal)->toBe(ReservationResult::REFUSAL_STAY_TYPE_MISMATCH)
        ->and(app(AddUnit::class)($setup['user'], $reservation, NewUnitData::from(dayUseLine($setup, ['roomId' => $setup['rooms'][1]->id])))->isRefused())->toBeFalse();
});

test('a day use plan on an overnight line is refused and a day use is never taken from a channel', function () {
    $setup = dayUseSetup();
    $overnightOnDayUse = ['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => $setup['date']->toDateString(), 'departureDate' => $setup['date']->addDay()->toDateString(), 'adults' => 2];

    expect(createDayUseReservation($setup, [$overnightOnDayUse])->refusal)->toBe(RateQuote::REFUSAL_PLAN_NOT_NIGHTLY)
        ->and(createDayUseReservation($setup, [dayUseLine($setup)], fromChannel: true)->refusal)->toBe(ReservationResult::REFUSAL_DAY_USE_NOT_FOR_CHANNEL)
        ->and(Reservation::query()->count())->toBe(0);
});

test('a dorm bed cannot be sold as day use', function () {
    $setup = dayUseSetup();
    $dorm = RoomType::factory()->bed()->for($setup['property'])->create();
    $setup['plan']->roomTypes()->attach($dorm);

    $result = createDayUseReservation($setup, [dayUseLine($setup, ['roomTypeId' => $dorm->id, 'roomId' => null])]);

    expect($result->isRefused())->toBeTrue()->and(ReservationUnit::query()->count())->toBe(0);
});

test('assigning a room or a bed to a day use unit is refused', function () {
    $setup = dayUseSetup();
    createDayUseReservation($setup, [dayUseLine($setup)]);
    $unit = ReservationUnit::query()->sole();

    expect(app(AssignRoom::class)($setup['user'], $unit, $setup['rooms'][1])->refusal)->toBe(AssignmentResult::REFUSAL_DAY_USE_UNIT)
        ->and(app(AssignBed::class)($setup['user'], $unit, Bed::factory()->create(['room_id' => $setup['rooms'][1]->id]))->refusal)->toBe(AssignmentResult::REFUSAL_DAY_USE_UNIT)
        ->and($unit->dayUse->refresh()->room_id)->toBe($setup['rooms'][0]->id);
});

test('add-ons: a one-off item works on the day and a nightly item is refused with no write', function () {
    $setup = dayUseSetup();
    $lunch = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '30.00', 'charging_unit' => ChargingUnit::PerItem]);
    $nightly = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '20.00', 'charging_unit' => ChargingUnit::PerNight]);
    $addOn = fn (ChargeItem $item) => ['chargeItemId' => $item->id, 'serviceDate' => $setup['date']->toDateString()];

    $refused = createDayUseReservation($setup, [dayUseLine($setup, ['addOns' => [$addOn($nightly)]])]);

    expect($refused->refusal)->toBe(AddOnResult::REFUSAL_NIGHTLY_ITEM_FOR_DAY_USE)
        ->and(Reservation::query()->count())->toBe(0);

    $result = createDayUseReservation($setup, [dayUseLine($setup, ['addOns' => [$addOn($lunch)]])]);

    expect($result->isRefused())->toBeFalse()
        ->and(ReservationUnit::query()->sole()->addOns()->sole()->service_date->toDateString())->toBe($setup['date']->toDateString());
});

test('children are recorded and the stay total counts the block', function () {
    $setup = dayUseSetup();
    $result = createDayUseReservation($setup, [dayUseLine($setup, ['childAges' => [5, 9]])]);

    expect(ReservationUnit::query()->sole()->children)->toBe(2)
        ->and((new StayTotal)->forReservation($result->reservation))->toBe('100.00');
});

test('a unit sold with an entered price shows Entered price as its plan and a Day use plan keeps its name', function () {
    $setup = dayUseSetup();
    $entered = createDayUseReservation($setup, [dayUseLine($setup, ['ratePlanId' => $setup['basic']->id, 'overrideTotal' => '90.00'])])->reservation->units()->sole();
    $planned = createDayUseReservation($setup, [dayUseLine($setup, ['roomId' => $setup['rooms'][1]->id])])->reservation->units()->sole();

    expect(app(StayLabel::class)->planForUnit($entered))->toBe('Entered price')
        ->and(app(StayLabel::class)->planForUnit($planned))->toBe('Day use');
});
