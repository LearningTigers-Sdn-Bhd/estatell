<?php

use App\Actions\FrontDesk\CancelUnits;
use App\Actions\FrontDesk\CancelUnitsResult;
use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\DefaultRoleEnum;
use App\Enums\PolicyScope;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Folio;
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
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A live property with an open business date (today), a fixed 75.00 cancellation policy and a reservation with
 * `$count` Confirmed units that arrive today for two nights, each in its own room.
 *
 * @return array{user: User, property: Property, reservation: Reservation, units: list<ReservationUnit>}
 */
function crSetup(int $count = 2, DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    TransactionCode::factory()->for($property)->system(SystemTransactionCode::CancellationRevenue)->create();
    RoomRevenuePolicy::factory()->for($property)->fixed('75.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create();

    $roomType = RoomType::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    foreach ([0, 1] as $offset) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => today()->addDays($offset)->toDateString(), 'amount' => '120.00']);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $units = [];

    for ($index = 0; $index < $count; $index++) {
        $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
        $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $plan->id,
            'arrival_date' => today()->toDateString(),
            'departure_date' => today()->addDays(2)->toDateString(),
        ]);
        $unit->nights()->update(['room_id' => $room->id]);
        $units[] = $unit;
    }

    return ['user' => $user, 'property' => $property, 'reservation' => $reservation, 'units' => $units];
}

/**
 * @param  array{user: User, reservation: Reservation, units: list<ReservationUnit>}  $setup
 * @param  array<int, string|null>  $expectedFees
 */
function crCancel(array $setup, ?array $unitIds = null, bool $doNotCharge = false, ?string $reason = null, bool $removeAddOns = false, array $expectedFees = []): CancelUnitsResult
{
    return app(CancelUnits::class)($setup['user'], $setup['reservation'], $unitIds ?? array_map(fn (ReservationUnit $unit): int => $unit->id, $setup['units']), $doNotCharge, $reason, $removeAddOns, $expectedFees);
}

/** @param array{property: Property, reservation: Reservation} $setup */
function crUrl(array $setup, string $route): string
{
    return route('reservations.'.$route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);
}

test('a group cancels in one go, posts each fee and writes one reservation entry', function () {
    $setup = crSetup(3);

    $result = crCancel($setup, reason: 'Group called off');

    $lines = FolioLine::query()->whereNull('parent_line_id')->get();
    $log = Activity::query()->where('event', 'reservation.cancelled')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($result->cancelled)->toBe(3)
        ->and($result->totalFee)->toBe('225.00')
        ->and($lines)->toHaveCount(3)
        ->and($lines->pluck('amount')->unique()->all())->toBe(['75.00'])
        ->and(ReservationUnit::query()->where('reservation_id', $setup['reservation']->id)->pluck('status')->unique()->all())->toBe([UnitStatus::Cancelled])
        ->and($setup['reservation']->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and(Activity::query()->where('event', 'reservation.unit_cancelled')->count())->toBe(3)
        ->and($log->causer_id)->toBe($setup['user']->id)
        ->and($log->properties['count'])->toBe(3)
        ->and($log->properties['total_fee'])->toBe('225.00')
        ->and($log->properties['reason'])->toBe('Group called off')
        ->and($log->properties['resolved_cancellation_request'])->toBeFalse();
});

test('ticking some units cancels only those', function () {
    $setup = crSetup(2);

    crCancel($setup, [$setup['units'][0]->id]);

    expect($setup['units'][0]->fresh()->status)->toBe(UnitStatus::Cancelled)
        ->and($setup['units'][1]->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($setup['reservation']->fresh()->status)->not->toBe(ReservationStatus::Cancelled);
});

test('one refusal cancels nothing and names the room', function () {
    $setup = crSetup(2);
    $roomNumber = $setup['units'][1]->nights()->first()->room->number;

    $result = crCancel($setup, expectedFees: [$setup['units'][0]->id => '75.00', $setup['units'][1]->id => '10.00']);

    expect($result->refusal)->toBe(UnitStayResult::REFUSAL_FEE_CHANGED)
        ->and($result->failedUnitId)->toBe($setup['units'][1]->id)
        ->and($result->failedRoom)->toBe('Room '.$roomNumber)
        ->and(ReservationUnit::query()->where('reservation_id', $setup['reservation']->id)->where('status', UnitStatus::Confirmed)->count())->toBe(2)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Folio::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0)
        ->and($setup['units'][0]->nights()->whereNotNull('room_id')->count())->toBe(2);
});

test('a checked in unit is not offered and refuses the whole cancel', function () {
    $setup = crSetup(2);
    $setup['units'][1]->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();

    $rows = collect($this->actingAs($setup['user'])->postJson(crUrl($setup, 'cancel-preview'))->assertOk()->json('rows'))->keyBy('unitId');

    expect($rows[$setup['units'][0]->id]['canCancel'])->toBeTrue()
        ->and($rows[$setup['units'][1]->id]['canCancel'])->toBeFalse()
        ->and($rows[$setup['units'][1]->id]['note'])->toBe('Checked in: use Check out or Early check-out.');

    $result = crCancel($setup);

    expect($result->refusal)->toBe(UnitStayResult::REFUSAL_INVALID_STATUS)
        ->and($setup['units'][0]->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(FolioLine::query()->count())->toBe(0);
});

test('do not charge needs a reason and posts no fee', function () {
    $setup = crSetup(2);

    $refused = crCancel($setup, doNotCharge: true);
    $done = crCancel($setup, doNotCharge: true, reason: 'Storm closed the island');

    expect($refused->refusal)->toBe(UnitStayResult::REFUSAL_REASON_REQUIRED)
        ->and($done->isRefused())->toBeFalse()
        ->and($done->totalFee)->toBe('0.00')
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.cancelled')->sole()->properties['did_not_charge'])->toBeTrue();
});

test('nothing ticked is refused', function () {
    $setup = crSetup(1);
    $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $foreign = ReservationUnit::factory()->for($other)->create();

    expect(crCancel($setup, [$foreign->id])->refusal)->toBe(CancelUnitsResult::REFUSAL_NO_UNITS)
        ->and($foreign->fresh()->status)->toBe(UnitStatus::Confirmed);
});

test('cancelling resolves the company portal request and the log says so', function () {
    $setup = crSetup(2);
    $setup['reservation']->forceFill(['cancellation_requested_at' => now(), 'cancellation_requested_by' => $setup['user']->id])->save();

    $preview = $this->actingAs($setup['user'])->postJson(crUrl($setup, 'cancel-preview'))->assertOk()->json();

    expect($preview['request']['at'])->toBe(today()->toDateString());

    crCancel($setup);

    $fresh = $setup['reservation']->fresh();

    expect($fresh->cancellation_requested_at)->toBeNull()
        ->and($fresh->cancellation_requested_by)->toBeNull()
        ->and(Activity::query()->where('event', 'reservation.cancelled')->sole()->properties['resolved_cancellation_request'])->toBeTrue();
});

test('the preview gives each fee with tax and the money held', function () {
    $setup = crSetup(2);
    $tax = Tax::factory()->for($setup['property'])->create(['rate' => '8.00', 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach(TransactionCode::query()->where('property_id', $setup['property']->id)->where('system_key', SystemTransactionCode::CancellationRevenue->value)->firstOrFail());

    $paid = Folio::factory()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioLine::factory()->payment('200.00')->create(['folio_id' => $paid->id]);
    $voided = Folio::factory()->voided()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioLine::factory()->payment('999.00')->create(['folio_id' => $voided->id]);

    $this->actingAs($setup['user'])->postJson(crUrl($setup, 'cancel-preview'))
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('paid', '200.00')
        ->assertJsonPath('rows.0.fee', '75.00')
        ->assertJsonPath('rows.0.feeTotal', '81.00')
        ->assertJsonPath('rows.1.canCancel', true);

    expect(Activity::query()->count())->toBe(0)->and(FolioLine::query()->whereNull('parent_line_id')->count())->toBe(2);
});

test('the cancel route cancels the ticked units and toasts', function () {
    $setup = crSetup(2);

    $this->actingAs($setup['user'])
        ->post(crUrl($setup, 'cancel'), [
            'unit_ids' => [$setup['units'][0]->id, $setup['units'][1]->id],
            'expected_fees' => [$setup['units'][0]->id => '75.00', $setup['units'][1]->id => '75.00'],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', '2 rooms cancelled.');

    expect($setup['reservation']->fresh()->status)->toBe(ReservationStatus::Cancelled);
});

test('the cancel route sends a refusal back with the room and cancels nothing', function () {
    $setup = crSetup(2);

    $this->actingAs($setup['user'])
        ->post(crUrl($setup, 'cancel'), [
            'unit_ids' => [$setup['units'][0]->id, $setup['units'][1]->id],
            'expected_fees' => [$setup['units'][1]->id => '10.00'],
        ])
        ->assertSessionHasErrors('expected_fee');

    expect(session('errors')->first('expected_fee'))->toStartWith('Room ')
        ->and(ReservationUnit::query()->where('status', UnitStatus::Cancelled)->count())->toBe(0);
});

test('do not charge is dropped for a user without front-desk.waive-fees', function () {
    $setup = crSetup(1, DefaultRoleEnum::FrontDesk);

    $this->actingAs($setup['user'])
        ->post(crUrl($setup, 'cancel'), ['unit_ids' => [$setup['units'][0]->id], 'do_not_charge' => true, 'reason' => 'Guest was ill'])
        ->assertRedirect();

    expect(FolioLine::query()->whereNull('parent_line_id')->sole()->amount)->toBe('75.00');
});

test('the cancel routes need reservations.manage and stay inside the property', function () {
    $setup = crSetup(1);
    $outsider = User::factory()->create(['property_id' => $setup['property']->id]);
    $stranger = crSetup(1);

    $this->actingAs($outsider)->post(crUrl($setup, 'cancel'), ['unit_ids' => [$setup['units'][0]->id]])->assertForbidden();
    $this->actingAs($outsider)->postJson(crUrl($setup, 'cancel-preview'))->assertForbidden();
    $this->actingAs($stranger['user'])->postJson(crUrl($setup, 'cancel-preview'))->assertNotFound();

    expect($setup['units'][0]->fresh()->status)->toBe(UnitStatus::Confirmed);
});

test('the page offers Cancel reservation only while a unit is Confirmed', function () {
    $setup = crSetup(1);
    $url = route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn ($page) => $page->where('canCancelReservation', true));

    $setup['units'][0]->forceFill(['status' => UnitStatus::Cancelled])->save();

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn ($page) => $page->where('canCancelReservation', false));
});
