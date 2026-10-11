<?php

require_once __DIR__.'/Support/DayUseSetup.php';

use App\Actions\FrontDesk\AutoAssignRooms;
use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\FrontDesk\CheckOutUnit;
use App\Actions\FrontDesk\ExtendDepartureTime;
use App\Actions\FrontDesk\MarkNoShow;
use App\Actions\FrontDesk\MoveUnit;
use App\Actions\FrontDesk\ReinstateUnit;
use App\Actions\Reservations\AssignmentResult;
use App\Enums\ChargingUnit;
use App\Enums\HousekeepingStatus;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomStatus;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Services\Availability\FreeUnits;
use App\Services\Catalogues\PolicyChargeCalculator;
use App\Services\Folio\ScheduledFolioRows;
use App\Services\NightAudit\AuditReadiness;
use App\Services\Reservations\UnitActionMenu;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with a Day use plan, the business date on the day use date, the cancellation and no-show policies
 * (the first night at 100%) and one Confirmed day use unit: 10:00 for 4 hours (MYR 100.00) in the first room.
 *
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function dayUseDesk(array $line = []): array
{
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $setup['property']->forceFill(['status' => 'live'])->save();
    BusinessDate::factory()->create(['property_id' => $setup['property']->id, 'date' => $setup['date']->toDateString()]);

    foreach ([SystemTransactionCode::CancellationRevenue, SystemTransactionCode::NoShowRevenue] as $code) {
        RoomRevenuePolicy::factory()->for($setup['property'])->forCode($code, PolicyScope::FirstNight)->percentage('100.00')->create();
    }

    $reservation = createDayUseReservation($setup, [dayUseLine($setup, $line)])->reservation;

    return [...$setup, 'actor' => $setup['user'], 'reservation' => $reservation, 'unit' => $reservation->units()->sole()];
}

/**
 * Another day use unit on the same date, in its own reservation.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 */
function otherDayUse(array $setup, array $line): ReservationUnit
{
    return createDayUseReservation($setup, [dayUseLine($setup, $line)])->reservation->units()->sole();
}

function setBusinessDate(array $setup, CarbonImmutable $date): void
{
    BusinessDate::query()->where('property_id', $setup['property']->id)->update(['date' => $date->toDateString()]);
}

test('check-in posts the block, its inclusions and every add-on, dated or not', function () {
    $setup = dayUseDesk();
    $lunch = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '30.00']);
    $setup['plan']->inclusions()->create([
        'charge_item_id' => $lunch->id, 'charging_unit' => ChargingUnit::PerGuest, 'quantity' => 1, 'allocated_amount' => '15.00',
        'starts_on' => InclusionStart::ArrivalDay, 'ends_on' => InclusionEnd::DepartureDay,
    ]);
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '25.00']);
    $undated = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $item->id]);
    $dated = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $item->id, 'service_date' => $setup['date']->toDateString()]);

    $result = app(CheckInUnit::class)($setup['actor'], $setup['unit']);

    $block = FolioLine::query()->where('idempotency_key', 'dayuse:'.$setup['unit']->id)->sole();
    $inclusion = FolioLine::query()->where('idempotency_key', 'dayuse:'.$setup['unit']->id.':incl:'.$setup['plan']->inclusions()->sole()->id)->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($block->description)->toBe('Day use · 10:00–14:00')
        ->and($block->transactionCode->code)->toBe('DAY_USE')
        ->and($block->posting_date->toDateString())->toBe($setup['date']->toDateString())
        ->and($block->source_id)->toBe($setup['unit']->id)
        ->and($block->amount)->toBe('70.00')
        ->and($inclusion->amount)->toBe('30.00')
        ->and($undated->fresh()->folio_line_id)->not->toBeNull()
        ->and($dated->fresh()->folio_line_id)->not->toBeNull()
        ->and(Activity::query()->where('event', 'reservation.unit_checked_in')->sole()->properties['room_id'])->toBe($setup['rooms'][0]->id);

    $blocks = app(ScheduledFolioRows::class)->forReservation($setup['property']->refresh(), $setup['reservation']->load('booker'), [$setup['unit']->id => '101']);

    expect($blocks)->toBe([]);
});

test('check-in is refused before the date and after it', function () {
    $setup = dayUseDesk();

    setBusinessDate($setup, $setup['date']->subDay());
    expect(app(CheckInUnit::class)($setup['actor'], $setup['unit'])->refusal)->toBe('arrival_not_reached');

    setBusinessDate($setup, $setup['date']->addDay());
    expect(app(CheckInUnit::class)($setup['actor'], $setup['unit'])->refusal)->toBe('stay_ended')
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(FolioLine::query()->count())->toBe(0);
});

test('a block that cannot post rolls the whole check-in back', function () {
    $setup = dayUseDesk();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '25.00']);
    $addOn = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $item->id]);
    TransactionCode::query()->where('property_id', $setup['property']->id)->where('system_key', SystemTransactionCode::DayUseRevenue->value)->delete();

    $result = app(CheckInUnit::class)($setup['actor'], $setup['unit']);

    expect($result->refusal)->toBe('charge_not_found')
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($addOn->fresh()->folio_line_id)->toBeNull()
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.unit_checked_in')->count())->toBe(0);
});

test('check-out marks the room of the day use Dirty', function () {
    $setup = dayUseDesk();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();
    RoomStatus::query()->where('room_id', $setup['rooms'][0]->id)->update(['status' => HousekeepingStatus::Clean]);

    $result = app(CheckOutUnit::class)($setup['actor'], $setup['unit']);

    expect($result->isRefused())->toBeFalse()
        ->and(RoomStatus::query()->where('room_id', $setup['rooms'][0]->id)->sole()->status)->toBe(HousekeepingStatus::Dirty);
});

test('cancel and no-show charge the block price and release the block', function (string $action, string $key) {
    $setup = dayUseDesk();

    $result = app($action)($setup['actor'], $setup['unit']);

    $dayUse = $setup['unit']->dayUse()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and(FolioLine::query()->where('idempotency_key', 'like', $key.':'.$setup['unit']->id.'%')->where('parent_line_id', null)->sole()->amount)->toBe('100.00')
        ->and($dayUse->released_at)->not->toBeNull()
        ->and($dayUse->room_id)->toBe($setup['rooms'][0]->id);

    $other = otherDayUse($setup, ['roomId' => $setup['rooms'][0]->id]);

    expect($other->dayUse()->sole()->room_id)->toBe($setup['rooms'][0]->id);
})->with([
    'cancel' => [CancelUnit::class, 'cancel'],
    'no-show' => [MarkNoShow::class, 'no_show'],
]);

test('the policy counts a day use as one night priced at the block, and re-quotes the current rate', function () {
    $setup = dayUseDesk();
    $policy = RoomRevenuePolicy::query()->where('code', SystemTransactionCode::CancellationRevenue)->sole();
    $calculator = app(PolicyChargeCalculator::class);

    expect((string) $calculator->calculate($policy, $setup['unit'])->amount->getAmount())->toBe('100.00');

    $policy->update(['applies_to' => PolicyScope::WholeStay, 'percent' => '50.00']);
    expect((string) $calculator->calculate($policy->fresh(), $setup['unit'])->amount->getAmount())->toBe('50.00');

    $policy->update(['applies_to' => PolicyScope::UnusedNights]);
    expect((string) $calculator->calculate($policy->fresh(), $setup['unit'], $setup['date'])->amount->getAmount())->toBe('0.00');

    $policy->update(['applies_to' => PolicyScope::OneCharge, 'percent_basis' => PolicyRateBasis::CurrentRate]);
    $setup['plan']->rates()->whereDate('date', $setup['date'])->firstOrFail()->hourPrices()->where('hours', 4)->update(['amount' => '200.00']);
    expect((string) $calculator->calculate($policy->fresh(), $setup['unit'])->amount->getAmount())->toBe('100.00');
});

test('reinstate refuses when the room was taken, and otherwise clears released_at and prices the block again', function () {
    $setup = dayUseDesk();
    app(CancelUnit::class)($setup['actor'], $setup['unit']);
    $taker = otherDayUse($setup, ['startTime' => '12:00', 'hours' => 2]);

    $refused = app(ReinstateUnit::class)($setup['actor'], $setup['unit']->fresh(), 'Guest is back');

    expect($refused->refusal)->toBe('not_available')
        ->and($setup['unit']->dayUse()->sole()->released_at)->not->toBeNull();

    app(CancelUnit::class)($setup['actor'], $taker);
    $setup['plan']->rates()->whereDate('date', $setup['date'])->firstOrFail()->hourPrices()->where('hours', 4)->update(['amount' => '110.00']);

    $done = app(ReinstateUnit::class)($setup['actor'], $setup['unit']->fresh(), 'Guest is back');

    $dayUse = $setup['unit']->dayUse()->sole();

    expect($done->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($dayUse->released_at)->toBeNull()
        ->and($dayUse->amount)->toBe('110.00');
});

test('reinstate is refused after the date', function () {
    $setup = dayUseDesk();
    app(CancelUnit::class)($setup['actor'], $setup['unit']);
    setBusinessDate($setup, $setup['date']->addDay());

    expect(app(ReinstateUnit::class)($setup['actor'], $setup['unit']->fresh(), 'Late')->refusal)->toBe('stay_ended');
});

test('move room puts the block in another free room for the same hours and keeps the amount', function () {
    $setup = dayUseDesk();

    $result = app(MoveUnit::class)($setup['actor'], $setup['unit'], $setup['rooms'][1], $setup['date'], 'Air-con fault');

    $dayUse = $setup['unit']->dayUse()->sole();
    $log = Activity::query()->where('event', 'reservation.unit_moved')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($dayUse->room_id)->toBe($setup['rooms'][1]->id)
        ->and($dayUse->amount)->toBe('100.00')
        ->and($log->properties['reason'])->toBe('Air-con fault')
        ->and($log->properties['previous']['room_ids'])->toBe([$setup['rooms'][0]->id]);
});

test('move room refuses a busy room, the same room and a missing reason', function () {
    $setup = dayUseDesk();
    otherDayUse($setup, ['roomId' => $setup['rooms'][1]->id, 'startTime' => '12:00', 'hours' => 2]);
    $move = fn ($room, string $reason = 'Fault') => app(MoveUnit::class)($setup['actor'], $setup['unit'], $room, $setup['date'], $reason)->refusal;

    expect($move($setup['rooms'][1]))->toBe(AssignmentResult::REFUSAL_ROOM_NOT_FREE)
        ->and($move($setup['rooms'][0]))->toBe(AssignmentResult::REFUSAL_UNCHANGED)
        ->and($move($setup['rooms'][1], ' '))->toBe(AssignmentResult::REFUSAL_REASON_REQUIRED)
        ->and($setup['unit']->dayUse()->sole()->room_id)->toBe($setup['rooms'][0]->id);

    setBusinessDate($setup, $setup['date']->addDay());

    expect($move($setup['rooms'][1]))->toBe(AssignmentResult::REFUSAL_NIGHT_IN_PAST);
});

test('an overnight stay skips a room whose day use overlaps the arrival, but not one that ends before it', function () {
    $setup = dayUseDesk(['startTime' => '12:00', 'hours' => 4]);
    $next = $setup['date']->addDay();
    $free = fn (string $arrivalTime) => array_map(
        fn ($room) => $room->id,
        app(FreeUnits::class)->forStay($setup['roomType'], $setup['date'], $next, null, $arrivalTime, '11:00'),
    );

    expect($free('15:00'))->toBe([$setup['rooms'][1]->id])
        ->and($free('16:00'))->toBe([$setup['rooms'][0]->id, $setup['rooms'][1]->id]);
});

test('a day use blocks the departure date until the departure time and every date in between', function () {
    $setup = dayUseDesk(['startTime' => '10:00', 'hours' => 2]);
    $freeIds = fn ($arrival, $departure, string $departureTime) => array_map(
        fn ($room) => $room->id,
        app(FreeUnits::class)->forStay($setup['roomType'], $arrival, $departure, null, '15:00', $departureTime),
    );

    expect($freeIds($setup['date']->subDay(), $setup['date'], '11:00'))->toBe([$setup['rooms'][1]->id])
        ->and($freeIds($setup['date']->subDay(), $setup['date'], '10:00'))->toBe([$setup['rooms'][0]->id, $setup['rooms'][1]->id])
        ->and($freeIds($setup['date']->subDay(), $setup['date']->addDay(), '11:00'))->toBe([$setup['rooms'][1]->id]);

    app(CancelUnit::class)($setup['actor'], $setup['unit']);

    expect($freeIds($setup['date']->subDay(), $setup['date']->addDay(), '11:00'))->toBe([$setup['rooms'][0]->id, $setup['rooms'][1]->id]);
});

test('auto assign skips the room of an overlapping day use', function () {
    $setup = dayUseDesk(['startTime' => '12:00', 'hours' => 4]);
    $overnight = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->withNights()->create([
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['basic']->id,
        'arrival_date' => $setup['date']->toDateString(),
        'departure_date' => $setup['date']->addDay()->toDateString(),
        'arrival_time' => '15:00:00',
        'departure_time' => '11:00:00',
    ]);

    $result = app(AutoAssignRooms::class)($setup['actor'], $overnight);

    expect($result->isRefused())->toBeFalse()
        ->and($overnight->nights()->sole()->room_id)->toBe($setup['rooms'][1]->id);
});

test('late check-out is refused when a day use starts in the room before the new time', function () {
    $setup = dayUseDesk(['startTime' => '12:00', 'hours' => 2]);
    $overnight = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->withNights()->checkedIn()->create([
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['basic']->id,
        'arrival_date' => $setup['date']->subDay()->toDateString(),
        'departure_date' => $setup['date']->toDateString(),
        'arrival_time' => '15:00:00',
        'departure_time' => '11:00:00',
    ]);
    $overnight->nights()->update(['room_id' => $setup['rooms'][0]->id]);

    $refused = app(ExtendDepartureTime::class)($setup['actor'], $overnight, '13:00');
    $beforeBlock = app(ExtendDepartureTime::class)($setup['actor'], $overnight, '11:30');

    expect($refused->refusal)->toBe('room_not_free')
        ->and($beforeBlock->refusal)->not->toBe('room_not_free')
        ->and($overnight->fresh()->departure_time)->toBe('11:00:00');
});

test('a day use still checked in shows "Day use · ended 14:00" in the audit readiness', function () {
    $setup = dayUseDesk();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();

    $items = app(AuditReadiness::class)->items($setup['property']->refresh());

    $item = collect($items)->firstWhere('reservationUnitId', $setup['unit']->id);

    expect($item->check->value)->toBe('departure_not_checked_out')
        ->and($item->detail)->toBe('Day use · ended 14:00');
});

test('the unit menu offers a day use unit only what its status and the date allow, never late check-out or change dates', function () {
    $setup = dayUseDesk();
    setPermissionsTeamId($setup['property']->id);
    $menu = fn (): array => array_map(fn ($action): string => $action->key->value, app(UnitActionMenu::class)->for($setup['actor'], $setup['unit']->fresh()));

    setBusinessDate($setup, $setup['date']->subDay());
    expect($menu())->toBe(['cancel', 'move-room']);

    setBusinessDate($setup, $setup['date']);
    expect($menu())->toBe(['check-in', 'cancel', 'no-show', 'move-room']);

    setBusinessDate($setup, $setup['date']->addDay());
    expect($menu())->toBe(['cancel', 'no-show']);

    setBusinessDate($setup, $setup['date']);
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();
    expect($menu())->toBe(['check-out', 'move-room']);

    setBusinessDate($setup, $setup['date']->addDay());
    expect($menu())->toBe(['check-out']);

    $setup['unit']->forceFill(['status' => UnitStatus::NoShow])->save();
    setBusinessDate($setup, $setup['date']);
    expect($menu())->toBe(['reinstate']);

    setBusinessDate($setup, $setup['date']->addDay());
    expect($menu())->toBe([]);
});

test('the move preview of a day use lists the rooms free for its hours and not a room with an overlapping block', function () {
    $setup = dayUseDesk();
    $url = route('reservations.units.move-preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
    $query = ['from_night' => $setup['date']->toDateString()];

    $free = $this->actingAs($setup['actor'])->postJson($url, $query)->assertOk()->json();

    expect($free['isDayUse'])->toBeTrue()
        ->and($free['hoursLabel'])->toBe('Day use · 10:00–14:00')
        ->and($free['current'])->toBe($setup['rooms'][0]->number)
        ->and($free['reasonRequired'])->toBeTrue()
        ->and(array_column($free['roomTypes'], 'id'))->toBe([$setup['roomType']->id])
        ->and(array_column($free['units'], 'label'))->toBe([$setup['rooms'][1]->number]);

    otherDayUse($setup, ['roomId' => $setup['rooms'][1]->id, 'startTime' => '12:00', 'hours' => 2]);

    $busy = $this->actingAs($setup['actor'])->postJson($url, $query)->assertOk()->json();

    expect($busy['units'])->toBe([]);
});
