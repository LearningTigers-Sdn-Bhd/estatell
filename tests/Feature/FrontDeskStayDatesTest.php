<?php

use App\Actions\FrontDesk\ChangeUnitDates;
use App\Actions\FrontDesk\ExtendDepartureTime;
use App\Actions\FrontDesk\ExtendStay;
use App\Actions\FrontDesk\StayDatesResult;
use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\ChargingUnit;
use App\Enums\PackageUnit;
use App\Enums\PolicyScope;
use App\Enums\PricingBasis;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date (today), the early departure (100% of the unused nights) and late
 * check-out (50% of one night) policies with their charge codes, a room type with two rooms, a plan with a 120.00 rate
 * from today for ten nights, and a Confirmed unit that arrives tomorrow for three nights (100.00 a night booked, room
 * 101 assigned).
 *
 * @param  array<string, mixed>  $unit
 * @return array{property: Property, actor: User, reservation: Reservation, unit: ReservationUnit, roomType: RoomType, plan: RatePlan, rooms: Collection<int, Room>}
 */
function datesSetup(array $unit = []): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    foreach ([[SystemTransactionCode::EarlyDepartureRevenue, PolicyScope::UnusedNights, '100.00'], [SystemTransactionCode::LateCheckoutRevenue, PolicyScope::OneCharge, '50.00']] as [$code, $scope, $percent]) {
        TransactionCode::factory()->for($property)->system($code)->create();
        RoomRevenuePolicy::factory()->for($property)->percentage($percent)->forCode($code, $scope)->create();
    }

    $roomType = RoomType::factory()->for($property)->create();
    $rooms = Room::factory()->count(2)->for($property)->create(['room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    for ($day = 0; $day < 10; $day++) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => today()->addDays($day)->toDateString(), 'amount' => '120.00']);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $roomType->id,
        'rate_plan_id' => $plan->id,
        'arrival_date' => today()->addDay()->toDateString(),
        'departure_date' => today()->addDays(4)->toDateString(),
        ...$unit,
    ]);
    $unit->nights()->update(['room_id' => $rooms->first()->id]);

    return ['property' => $property, 'actor' => User::factory()->create(['property_id' => $property->id]), 'reservation' => $reservation, 'unit' => $unit, 'roomType' => $roomType, 'plan' => $plan, 'rooms' => $rooms];
}

/**
 * @param  array{unit: ReservationUnit, actor: User}  $setup
 */
function changeDates(array $setup, ?CarbonInterface $arrival = null, ?CarbonInterface $departure = null, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null, ?string $override = null): StayDatesResult
{
    return app(ChangeUnitDates::class)($setup['actor'], $setup['unit'], $arrival ?? $setup['unit']->arrival_date, $departure ?? $setup['unit']->departure_date, $doNotCharge, $reason, $expectedFee, $override);
}

/**
 * @return array<string, string>
 */
function nightAmounts(ReservationUnit $unit): array
{
    return $unit->nights()->orderBy('date')->get()->mapWithKeys(fn ($night): array => [$night->date->toDateString() => $night->amount])->all();
}

test('extending a stay prices only the new nights, keeps the old nights and the room, and logs it', function () {
    $setup = datesSetup();
    $oldNights = nightAmounts($setup['unit']);

    $result = changeDates($setup, departure: today()->addDays(6));

    $nights = $setup['unit']->nights()->orderBy('date')->get();
    $log = Activity::query()->where('event', 'reservation.unit_dates_changed')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->departure_date->toDateString())->toBe(today()->addDays(6)->toDateString())
        ->and($nights->pluck('amount')->all())->toBe(['100.00', '100.00', '100.00', '120.00', '120.00'])
        ->and(array_slice(nightAmounts($setup['unit']), 0, 3))->toBe($oldNights)
        ->and($nights->pluck('room_id')->unique()->all())->toBe([$setup['rooms']->first()->id])
        ->and($result->previousTotal->getAmount()->__toString())->toBe('300.00')
        ->and($result->newTotal->getAmount()->__toString())->toBe('540.00')
        ->and($result->unassignedNights)->toBe(0)
        ->and($result->feeCharged)->toBeFalse()
        ->and(FolioLine::query()->count())->toBe(0)
        ->and($log->causer_id)->toBe($setup['actor']->id)
        ->and($log->subject_id)->toBe($setup['reservation']->id)
        ->and($log->properties['previous_departure_date'])->toBe(today()->addDays(4)->toDateString())
        ->and($log->properties['total'])->toBe('540.00');
});

test('extending gives each nightly add-on rows for the new nights and leaves the others', function () {
    $setup = datesSetup();
    $nightly = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'charging_unit' => ChargingUnit::PerNight]);
    $perStay = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'charging_unit' => ChargingUnit::PerStay]);

    foreach ([1, 2, 3] as $offset) {
        UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $nightly->id, 'unit_amount' => '15.00', 'service_date' => today()->addDays($offset)->toDateString()]);
    }

    UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $perStay->id]);

    changeDates($setup, departure: today()->addDays(6));

    $rows = $setup['unit']->addOns()->where('charge_item_id', $nightly->id)->orderBy('service_date')->get();

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('unit_amount')->unique()->all())->toBe(['15.00'])
        ->and($rows->last()->service_date->toDateString())->toBe(today()->addDays(5)->toDateString())
        ->and($setup['unit']->addOns()->where('charge_item_id', $perStay->id)->count())->toBe(1);
});

test('an in-house unit can be extended, and the new night keeps its room', function () {
    $setup = datesSetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDay(), 'arrival_date' => today()->subDay()->toDateString(), 'departure_date' => today()->addDay()->toDateString()]);

    $result = app(ExtendStay::class)($setup['actor'], $setup['unit'], today()->addDays(3));

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->nights()->count())->toBe(4)
        ->and($setup['unit']->nights()->whereNull('room_id')->count())->toBe(0);
});

test('a new night whose room is taken stays unassigned and is counted', function () {
    $setup = datesSetup();
    $other = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->withNights()->create([
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => today()->addDays(4)->toDateString(),
        'departure_date' => today()->addDays(5)->toDateString(),
    ]);
    $other->nights()->update(['room_id' => $setup['rooms']->first()->id]);

    $result = changeDates($setup, departure: today()->addDays(5));

    expect($result->isRefused())->toBeFalse()
        ->and($result->unassignedNights)->toBe(1)
        ->and($setup['unit']->nights()->whereNull('room_id')->count())->toBe(1);
});

test('moving the arrival earlier or later adds or removes nights at the front with no fee', function () {
    $setup = datesSetup();

    $earlier = changeDates($setup, arrival: today());

    expect($earlier->isRefused())->toBeFalse()
        ->and(array_keys(nightAmounts($setup['unit']))[0])->toBe(today()->toDateString())
        ->and(nightAmounts($setup['unit'])[today()->toDateString()])->toBe('120.00');

    $setup['unit']->refresh();
    $later = changeDates($setup, arrival: today()->addDays(2));

    expect($later->isRefused())->toBeFalse()
        ->and($later->feeCharged)->toBeFalse()
        ->and(array_keys(nightAmounts($setup['unit'])))->toBe([today()->addDays(2)->toDateString(), today()->addDays(3)->toDateString()])
        ->and(FolioLine::query()->count())->toBe(0);
});

test('a shorter stay posts the early departure fee, removes the unused nights and their unposted add-ons', function () {
    $setup = datesSetup();
    $nightly = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'charging_unit' => ChargingUnit::PerNight]);

    foreach ([1, 2, 3] as $offset) {
        UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $nightly->id, 'service_date' => today()->addDays($offset)->toDateString()]);
    }

    $result = changeDates($setup, departure: today()->addDays(3));

    $line = FolioLine::query()->whereNull('parent_line_id')->sole();
    $log = Activity::query()->where('event', 'reservation.unit_dates_changed')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($result->fee)->toBe('100.00')
        ->and($result->feeCharged)->toBeTrue()
        ->and($setup['unit']->fresh()->departure_date->toDateString())->toBe(today()->addDays(3)->toDateString())
        ->and($setup['unit']->nights()->count())->toBe(2)
        ->and($setup['unit']->addOns()->count())->toBe(2)
        ->and($line->amount)->toBe('100.00')
        ->and($line->idempotency_key)->toBe('early_departure:'.$setup['unit']->id.':1')
        ->and($log->properties['fee'])->toBe('100.00')
        ->and($log->properties['total'])->toBe('200.00');
});

test('a shorter stay with do not charge posts no fee, and a stale fee is refused', function () {
    $setup = datesSetup();

    $stale = changeDates($setup, departure: today()->addDays(3), expectedFee: '80.00');

    expect($stale->refusal)->toBe(UnitStayResult::REFUSAL_FEE_CHANGED)
        ->and($setup['unit']->nights()->count())->toBe(3);

    $waived = changeDates($setup, departure: today()->addDays(3), doNotCharge: true, reason: 'Family emergency');

    expect($waived->isRefused())->toBeFalse()
        ->and($waived->feeCharged)->toBeFalse()
        ->and($waived->fee)->toBe('100.00')
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.unit_dates_changed')->sole()->properties['reason'])->toBe('Family emergency');
});

test('a change that moves the arrival too is not an early departure', function () {
    $setup = datesSetup();

    $result = changeDates($setup, arrival: today(), departure: today()->addDays(3));

    expect($result->isRefused())->toBeFalse()
        ->and($result->feeCharged)->toBeFalse()
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(array_keys(nightAmounts($setup['unit'])))->toBe([today()->toDateString(), today()->addDay()->toDateString(), today()->addDays(2)->toDateString()]);
});

test('a restriction on the new nights needs an override reason', function () {
    $setup = datesSetup();
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', today()->addDays(4))->update(['stop_sell' => true]);

    $refused = changeDates($setup, departure: today()->addDays(5));

    expect($refused->refusal)->toBe(UnitStayResult::REFUSAL_RESTRICTED)
        ->and($setup['unit']->nights()->count())->toBe(3);

    $overridden = changeDates($setup, departure: today()->addDays(5), override: 'Manager agreed');

    expect($overridden->isRefused())->toBeFalse()
        ->and(Activity::query()->where('event', 'reservation.unit_dates_changed')->sole()->properties['restriction_override_reason'])->toBe('Manager agreed');
});

test('changing dates is refused and writes nothing', function (string $case, string $refusal) {
    $setup = match ($case) {
        'checked out' => datesSetup(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()]),
        'cancelled' => datesSetup(['status' => UnitStatus::Cancelled]),
        'no business date' => tap(datesSetup(), fn (array $setup) => BusinessDate::query()->where('property_id', $setup['property']->id)->delete()),
        'invalid stay', 'unchanged', 'arrival in the past', 'sold out', 'no rate', 'policy missing', 'reason missing' => datesSetup(),
        'package plan' => tap(datesSetup(), fn (array $setup) => $setup['plan']->update(['pricing_basis' => PricingBasis::Package, 'package_unit' => PackageUnit::PerGuest, 'fixed_nights' => 3])),
        'in-house arrival moved' => datesSetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDay(), 'arrival_date' => today()->subDay()->toDateString(), 'departure_date' => today()->addDay()->toDateString()]),
        'in-house departure in the past' => datesSetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDays(3), 'arrival_date' => today()->subDays(3)->toDateString(), 'departure_date' => today()->addDay()->toDateString()]),
    };

    if ($case === 'sold out') {
        ReservationUnit::factory()->count(2)->withNights()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->create([
            'room_type_id' => $setup['roomType']->id,
            'rate_plan_id' => $setup['plan']->id,
            'arrival_date' => today()->addDays(4)->toDateString(),
            'departure_date' => today()->addDays(5)->toDateString(),
        ]);
    }

    if ($case === 'policy missing') {
        RoomRevenuePolicy::query()->where('property_id', $setup['property']->id)->delete();
    }

    $unit = $setup['unit'];
    $departure = $unit->departure_date->toImmutable();
    $arrival = $unit->arrival_date->toImmutable();
    $before = nightAmounts($unit);

    $result = match ($case) {
        'invalid stay' => changeDates($setup, departure: $arrival),
        'unchanged' => changeDates($setup),
        'arrival in the past' => changeDates($setup, arrival: today()->subDay()),
        'in-house arrival moved' => changeDates($setup, arrival: today()->subDays(2)),
        'in-house departure in the past' => changeDates($setup, departure: today()->subDay()),
        'sold out' => changeDates($setup, departure: today()->addDays(5)),
        'no rate' => changeDates($setup, departure: today()->addDays(12)),
        'policy missing', 'reason missing' => changeDates($setup, departure: today()->addDays(3), doNotCharge: $case === 'reason missing'),
        default => changeDates($setup, departure: $departure->addDay()),
    };

    expect($result->refusal)->toBe($refusal)
        ->and(nightAmounts($unit))->toBe($before)
        ->and($unit->fresh()->departure_date->toDateString())->toBe($departure->toDateString())
        ->and($unit->fresh()->arrival_date->toDateString())->toBe($arrival->toDateString())
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    ['checked out', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['cancelled', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['no business date', UnitStayResult::REFUSAL_NO_BUSINESS_DATE],
    ['invalid stay', UnitStayResult::REFUSAL_INVALID_STAY],
    ['unchanged', UnitStayResult::REFUSAL_DATES_UNCHANGED],
    ['arrival in the past', UnitStayResult::REFUSAL_ARRIVAL_IN_PAST],
    ['in-house arrival moved', UnitStayResult::REFUSAL_ARRIVAL_LOCKED],
    ['in-house departure in the past', UnitStayResult::REFUSAL_DEPARTURE_IN_PAST],
    ['package plan', UnitStayResult::REFUSAL_PACKAGE_PLAN],
    ['sold out', UnitStayResult::REFUSAL_NOT_AVAILABLE],
    ['no rate', 'rate_missing'],
    ['policy missing', UnitStayResult::REFUSAL_POLICY_NOT_FOUND],
    ['reason missing', UnitStayResult::REFUSAL_REASON_REQUIRED],
]);

test('extend stay refuses a date that is not later and writes nothing', function () {
    $setup = datesSetup();

    $result = app(ExtendStay::class)($setup['actor'], $setup['unit'], $setup['unit']->departure_date);

    expect($result->refusal)->toBe(UnitStayResult::REFUSAL_NOT_AN_EXTENSION)
        ->and($setup['unit']->nights()->count())->toBe(3)
        ->and(Activity::query()->count())->toBe(0);
});

/**
 * A CheckedIn unit on its departure date, with a standard 11:00 departure time and a booked rate of 100.00 a night.
 *
 * @return array{property: Property, actor: User, reservation: Reservation, unit: ReservationUnit, roomType: RoomType, plan: RatePlan, rooms: Collection<int, Room>}
 */
function lateCheckoutSetup(): array
{
    return datesSetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()->subDay(), 'arrival_date' => today()->subDay()->toDateString(), 'departure_date' => today()->toDateString(), 'departure_time' => '11:00:00']);
}

function lateCheckout(array $setup, string $time = '16:00', bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null): UnitStayResult
{
    return app(ExtendDepartureTime::class)($setup['actor'], $setup['unit'], $time, $doNotCharge, $reason, $expectedFee);
}

test('a late check-out moves the departure time and posts half a night', function () {
    $setup = lateCheckoutSetup();

    $result = lateCheckout($setup);

    $line = FolioLine::query()->whereNull('parent_line_id')->sole();
    $log = Activity::query()->where('event', 'reservation.unit_departure_time_extended')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->departure_time)->toBe('16:00:00')
        ->and($setup['unit']->fresh()->departure_date->toDateString())->toBe(today()->toDateString())
        ->and($line->amount)->toBe('50.00')
        ->and($line->idempotency_key)->toBe('late_checkout:'.$setup['unit']->id.':1')
        ->and($log->properties['previous_departure_time'])->toBe('11:00')
        ->and($log->properties['departure_time'])->toBe('16:00')
        ->and($log->properties['fee_folio_line_id'])->toBe($line->id)
        ->and($log->causer_id)->toBe($setup['actor']->id);
});

test('a second late check-out on the same day is charged again', function () {
    $setup = lateCheckoutSetup();

    lateCheckout($setup, '14:00');
    lateCheckout($setup, '18:00');

    expect(FolioLine::query()->orderBy('id')->pluck('idempotency_key')->all())
        ->toBe(['late_checkout:'.$setup['unit']->id.':1', 'late_checkout:'.$setup['unit']->id.':2'])
        ->and($setup['unit']->fresh()->departure_time)->toBe('18:00:00');
});

test('a late check-out with do not charge posts no fee', function () {
    $setup = lateCheckoutSetup();

    $result = lateCheckout($setup, doNotCharge: true, reason: 'Guest of the owner');

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->departure_time)->toBe('16:00:00')
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.unit_departure_time_extended')->sole()->properties['reason'])->toBe('Guest of the owner');
});

test('a late check-out is refused and writes nothing', function (string $case, string $refusal) {
    $setup = match ($case) {
        'not in-house' => datesSetup(),
        'not departure date' => datesSetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now(), 'arrival_date' => today()->toDateString(), 'departure_date' => today()->addDay()->toDateString()]),
        'no business date' => tap(lateCheckoutSetup(), fn (array $setup) => BusinessDate::query()->where('property_id', $setup['property']->id)->delete()),
        default => lateCheckoutSetup(),
    };

    if ($case === 'policy missing') {
        RoomRevenuePolicy::query()->where('property_id', $setup['property']->id)->delete();
    }

    $time = match ($case) {
        'same time' => '11:00',
        'earlier time' => '09:30',
        'not a time' => '24:30',
        default => '16:00',
    };

    $before = $setup['unit']->fresh()->departure_time;
    $result = lateCheckout($setup, $time, doNotCharge: $case === 'reason missing', expectedFee: $case === 'stale fee' ? '10.00' : null);
    $departureTime = $setup['unit']->fresh()->departure_time;

    expect($result->refusal)->toBe($refusal)
        ->and($departureTime)->toBe($before)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    ['not in-house', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['not departure date', UnitStayResult::REFUSAL_NOT_DEPARTURE_DATE],
    ['no business date', UnitStayResult::REFUSAL_NO_BUSINESS_DATE],
    ['same time', UnitStayResult::REFUSAL_TIME_NOT_LATER],
    ['earlier time', UnitStayResult::REFUSAL_TIME_NOT_LATER],
    ['not a time', UnitStayResult::REFUSAL_TIME_NOT_LATER],
    ['reason missing', UnitStayResult::REFUSAL_REASON_REQUIRED],
    ['policy missing', UnitStayResult::REFUSAL_POLICY_NOT_FOUND],
    ['stale fee', UnitStayResult::REFUSAL_FEE_CHANGED],
]);
