<?php

use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\MarkNoShow;
use App\Actions\FrontDesk\ReinstateUnit;
use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\FolioStatus;
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
use App\Models\ReservationUnitChild;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date (today), the cancellation and no-show charge codes, a fixed 75.00
 * cancellation fee and a fixed 60.00 no-show fee, and a Confirmed unit that arrives today for two nights (100.00 a
 * night, room 101 assigned) with one room type that has two rooms and a plan with a 120.00 rate on those nights.
 *
 * @param  array<string, mixed>  $unit
 * @return array{property: Property, actor: User, reservation: Reservation, unit: ReservationUnit, roomType: RoomType, plan: RatePlan}
 */
function cancellationSetup(array $unit = []): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    foreach ([[SystemTransactionCode::CancellationRevenue, '75.00'], [SystemTransactionCode::NoShowRevenue, '60.00']] as [$code, $amount]) {
        TransactionCode::factory()->for($property)->system($code)->create();
        RoomRevenuePolicy::factory()->for($property)->fixed($amount)->forCode($code, PolicyScope::WholeStay)->create();
    }

    $roomType = RoomType::factory()->for($property)->create();
    $rooms = Room::factory()->count(2)->for($property)->create(['room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    foreach ([today(), today()->addDay()] as $date) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $date->toDateString(), 'amount' => '120.00']);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $roomType->id,
        'rate_plan_id' => $plan->id,
        'arrival_date' => today()->toDateString(),
        'departure_date' => today()->addDays(2)->toDateString(),
        ...$unit,
    ]);
    $unit->nights()->update(['room_id' => $rooms->first()->id]);

    return ['property' => $property, 'actor' => User::factory()->create(['property_id' => $property->id]), 'reservation' => $reservation, 'unit' => $unit, 'roomType' => $roomType, 'plan' => $plan];
}

/**
 * @param  array{unit: ReservationUnit, actor: User}  $setup
 */
function cancelUnit(array $setup, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null, bool $removeAddOns = false): UnitStayResult
{
    return app(CancelUnit::class)($setup['actor'], $setup['unit'], $doNotCharge, $reason, $expectedFee, $removeAddOns);
}

/**
 * @param  array{unit: ReservationUnit, actor: User}  $setup
 */
function markNoShow(array $setup, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null, bool $removeAddOns = false): UnitStayResult
{
    return app(MarkNoShow::class)($setup['actor'], $setup['unit'], $doNotCharge, $reason, $expectedFee, $removeAddOns);
}

function addPolicyTax(Property $property, SystemTransactionCode $code, string $rate = '8.00'): Tax
{
    $tax = Tax::factory()->for($property)->create(['rate' => $rate, 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach(TransactionCode::query()->where('property_id', $property->id)->where('system_key', $code->value)->firstOrFail());

    return $tax;
}

test('cancel posts the policy fee on a new unit folio, cancels the unit and logs it', function () {
    $setup = cancellationSetup();

    $result = cancelUnit($setup);

    $line = FolioLine::query()->whereNull('parent_line_id')->sole();
    $log = Activity::query()->where('event', 'reservation.unit_cancelled')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Cancelled)
        ->and($setup['reservation']->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($line->amount)->toBe('75.00')
        ->and($line->idempotency_key)->toBe('cancel:'.$setup['unit']->id.':1')
        ->and($line->posting_date->toDateString())->toBe(today()->toDateString())
        ->and($line->folio->reservation_unit_id)->toBe($setup['unit']->id)
        ->and($line->source_type)->toBe($setup['unit']->getMorphClass())->and($line->source_id)->toBe($setup['unit']->id)
        ->and($log->causer_id)->toBe($setup['actor']->id)
        ->and($log->subject_id)->toBe($setup['reservation']->id)
        ->and($log->properties['fee'])->toBe('75.00')
        ->and($log->properties['fee_folio_line_id'])->toBe($line->id)
        ->and($log->properties['did_not_charge'])->toBeFalse();
});

test('cancel and no-show release the rooms and keep the nights', function (string $action, string $event) {
    $setup = cancellationSetup();
    $roomId = $setup['unit']->nights()->value('room_id');

    $action($setup);

    $log = Activity::query()->where('event', $event)->sole();

    expect($setup['unit']->nights()->count())->toBe(2)
        ->and($setup['unit']->nights()->whereNotNull('room_id')->count())->toBe(0)
        ->and($setup['unit']->nights()->pluck('amount')->all())->toBe(['100.00', '100.00'])
        ->and($log->properties['released_room_ids'])->toBe([$roomId])
        ->and($log->properties['released_bed_ids'])->toBe([]);
})->with([
    ['cancelUnit', 'reservation.unit_cancelled'],
    ['markNoShow', 'reservation.unit_no_show'],
]);

test('a refused cancel keeps the rooms', function () {
    $setup = cancellationSetup();

    cancelUnit($setup, doNotCharge: true);

    expect($setup['unit']->nights()->whereNotNull('room_id')->count())->toBe(2);
});

test('a cancellation fee carries the taxes of its code as child lines', function () {
    $setup = cancellationSetup();
    addPolicyTax($setup['property'], SystemTransactionCode::CancellationRevenue);

    cancelUnit($setup);

    $charge = FolioLine::query()->whereNull('parent_line_id')->sole();

    expect($charge->amount)->toBe('75.00')
        ->and($charge->children()->sole()->amount)->toBe('6.00');
});

test('a fee posts on the folio the routing rules choose', function () {
    $setup = cancellationSetup();
    $folio = Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    cancelUnit($setup);

    expect(FolioLine::query()->sole()->folio_id)->toBe($folio->id)
        ->and(Folio::query()->count())->toBe(1);
});

test('a cancellation inside the free window posts nothing', function () {
    $setup = cancellationSetup(['arrival_date' => today()->addDays(5)->toDateString(), 'departure_date' => today()->addDays(7)->toDateString()]);
    RoomRevenuePolicy::query()->where('property_id', $setup['property']->id)->where('code', SystemTransactionCode::CancellationRevenue)->update(['free_until_days' => 2]);

    $result = cancelUnit($setup);

    $log = Activity::query()->where('event', 'reservation.unit_cancelled')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Cancelled)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Folio::query()->count())->toBe(0)
        ->and($log->properties['within_free_window'])->toBeTrue();
});

test('do not charge posts no fee and logs the reason', function () {
    $setup = cancellationSetup();

    $result = cancelUnit($setup, doNotCharge: true, reason: 'Guest was ill');

    $log = Activity::query()->where('event', 'reservation.unit_cancelled')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Cancelled)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and($log->properties['did_not_charge'])->toBeTrue()
        ->and($log->properties['reason'])->toBe('Guest was ill')
        ->and($log->properties['fee'])->toBe('75.00');
});

test('a fee that differs from the one the form showed is refused', function () {
    $setup = cancellationSetup();

    $stale = cancelUnit($setup, expectedFee: '50.00');

    expect($stale->refusal)->toBe(UnitStayResult::REFUSAL_FEE_CHANGED)
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);

    expect(cancelUnit($setup, expectedFee: '75.00')->isRefused())->toBeFalse();
});

test('cancel and no-show are refused and write nothing', function (string $case, string $refusal) {
    $setup = match ($case) {
        'checked in' => cancellationSetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]),
        'already cancelled' => cancellationSetup(['status' => UnitStatus::Cancelled]),
        'no business date' => tap(cancellationSetup(), fn (array $setup) => BusinessDate::query()->where('property_id', $setup['property']->id)->delete()),
        'no reason' => cancellationSetup(),
        'no policy' => tap(cancellationSetup(), fn (array $setup) => RoomRevenuePolicy::query()->where('property_id', $setup['property']->id)->delete()),
        'no charge code' => tap(cancellationSetup(), fn (array $setup) => TransactionCode::query()->where('property_id', $setup['property']->id)->update(['active' => false])),
        'closed folio' => tap(cancellationSetup(), fn (array $setup) => Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id, 'status' => FolioStatus::Closed])),
        'foreign tax without nationality' => tap(cancellationSetup(), function (array $setup): void {
            $setup['reservation']->booker->update(['nationality_code' => null]);
            addPolicyTax($setup['property'], SystemTransactionCode::CancellationRevenue)->update(['foreign_guests_only' => true]);
            addPolicyTax($setup['property'], SystemTransactionCode::NoShowRevenue)->update(['foreign_guests_only' => true]);
        }),
    };

    $status = $setup['unit']->status;
    $lines = FolioLine::query()->count();

    foreach ([cancelUnit($setup, doNotCharge: $case === 'no reason'), markNoShow($setup, doNotCharge: $case === 'no reason')] as $result) {
        expect($result->refusal)->toBe($refusal);
    }

    expect($setup['unit']->fresh()->status)->toBe($status)
        ->and(FolioLine::query()->count())->toBe($lines)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    ['checked in', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['already cancelled', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['no business date', UnitStayResult::REFUSAL_NO_BUSINESS_DATE],
    ['no reason', UnitStayResult::REFUSAL_REASON_REQUIRED],
    ['no policy', UnitStayResult::REFUSAL_POLICY_NOT_FOUND],
    ['no charge code', UnitStayResult::REFUSAL_CHARGE_NOT_FOUND],
    ['closed folio', 'folio_not_open'],
    ['foreign tax without nationality', 'nationality_required'],
]);

test('a unit cancelled again after it came back is charged again', function () {
    $setup = cancellationSetup();

    cancelUnit($setup);
    ReservationUnit::query()->whereKey($setup['unit']->id)->update(['status' => UnitStatus::Confirmed]);
    cancelUnit($setup);

    expect(FolioLine::query()->orderBy('id')->pluck('idempotency_key')->all())
        ->toBe(['cancel:'.$setup['unit']->id.':1', 'cancel:'.$setup['unit']->id.':2']);
});

test('remove add-ons deletes the unposted add-ons and keeps the posted one', function () {
    $setup = cancellationSetup();
    $unposted = UnitAddOn::factory()->count(2)->create(['reservation_unit_id' => $setup['unit']->id]);
    $posted = UnitAddOn::factory()->posted()->create(['reservation_unit_id' => $setup['unit']->id]);

    cancelUnit($setup, removeAddOns: true);

    $log = Activity::query()->where('event', 'reservation.unit_cancelled')->sole();

    expect(UnitAddOn::query()->pluck('id')->all())->toBe([$posted->id])
        ->and($log->properties['removed_add_on_ids'])->toBe($unposted->pluck('id')->all())
        ->and(Activity::query()->where('event', 'reservation.add_on_removed')->count())->toBe(2);
});

test('without the option the add-ons stay on a cancelled unit', function () {
    $setup = cancellationSetup();
    UnitAddOn::factory()->count(2)->create(['reservation_unit_id' => $setup['unit']->id]);

    cancelUnit($setup);

    expect(UnitAddOn::query()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'reservation.unit_cancelled')->sole()->properties['removed_add_on_ids'])->toBe([]);
});

test('no-show posts the no-show fee, marks the unit and logs it', function () {
    $setup = cancellationSetup();

    $result = markNoShow($setup);

    $line = FolioLine::query()->whereNull('parent_line_id')->sole();
    $log = Activity::query()->where('event', 'reservation.unit_no_show')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::NoShow)
        ->and($line->amount)->toBe('60.00')
        ->and($line->idempotency_key)->toBe('no_show:'.$setup['unit']->id.':1')
        ->and($log->causer_id)->toBe($setup['actor']->id)
        ->and($log->properties['fee_folio_line_id'])->toBe($line->id);
});

test('a no-show is refused before the arrival date', function () {
    $setup = cancellationSetup(['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(3)->toDateString()]);

    $result = markNoShow($setup);

    expect($result->refusal)->toBe(UnitStayResult::REFUSAL_ARRIVAL_NOT_REACHED)
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(Activity::query()->count())->toBe(0);
});

test('a no-show with do not charge and remove add-ons posts nothing and deletes the unposted add-ons', function () {
    $setup = cancellationSetup();
    UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id]);

    $result = markNoShow($setup, doNotCharge: true, reason: 'Called ahead', removeAddOns: true);

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::NoShow)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(UnitAddOn::query()->count())->toBe(0);
});

test('reinstate confirms the unit, prices it again, clears the rooms and logs the reason', function (string $from) {
    $setup = cancellationSetup(['status' => UnitStatus::from($from)]);
    ReservationUnitChild::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'age' => 5]);
    $fee = FolioLine::factory()->create();

    $result = app(ReinstateUnit::class)($setup['actor'], $setup['unit'], 'Guest called back');

    $log = Activity::query()->where('event', 'reservation.unit_reinstated')->sole();
    $nights = $setup['unit']->nights()->get();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($setup['reservation']->fresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($nights->pluck('amount')->all())->toBe(['120.00', '120.00'])
        ->and($nights->pluck('room_id')->filter()->all())->toBe([])
        ->and($nights->pluck('bed_id')->filter()->all())->toBe([])
        ->and($log->causer_id)->toBe($setup['actor']->id)
        ->and($log->properties['reason'])->toBe('Guest called back')
        ->and($log->properties['from_status'])->toBe($from)
        ->and($log->properties['previous_total'])->toBe('200.00')
        ->and($log->properties['total'])->toBe('240.00')
        ->and($fee->fresh())->not->toBeNull();
})->with(['cancelled', 'no_show']);

test('reinstate keeps a stay override as the night amount', function () {
    $setup = cancellationSetup(['status' => UnitStatus::Cancelled]);
    $setup['unit']->nights()->update(['override_amount' => '90.00', 'amount' => '90.00']);

    app(ReinstateUnit::class)($setup['actor'], $setup['unit'], 'Back again');

    expect($setup['unit']->nights()->pluck('amount')->all())->toBe(['90.00', '90.00'])
        ->and($setup['unit']->nights()->first()->base_amount)->toBe('120.00');
});

test('reinstate is refused and writes nothing', function (string $case, string $refusal) {
    $setup = match ($case) {
        'confirmed' => cancellationSetup(),
        'checked out' => cancellationSetup(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()]),
        'blank reason' => cancellationSetup(['status' => UnitStatus::Cancelled]),
        'no business date' => tap(cancellationSetup(['status' => UnitStatus::Cancelled]), fn (array $setup) => BusinessDate::query()->where('property_id', $setup['property']->id)->delete()),
        'stay ended' => cancellationSetup(['status' => UnitStatus::NoShow, 'arrival_date' => today()->subDays(2)->toDateString(), 'departure_date' => today()->toDateString()]),
        'sold out' => tap(cancellationSetup(['status' => UnitStatus::Cancelled]), fn (array $setup) => ReservationUnit::factory()->count(2)->withNights()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->create([
            'room_type_id' => $setup['roomType']->id,
            'rate_plan_id' => $setup['plan']->id,
            'arrival_date' => today()->toDateString(),
            'departure_date' => today()->addDays(2)->toDateString(),
        ])),
        'no rate' => tap(cancellationSetup(['status' => UnitStatus::Cancelled]), fn (array $setup) => Rate::query()->where('rate_plan_id', $setup['plan']->id)->delete()),
    };

    $status = $setup['unit']->status;
    $nights = $setup['unit']->nights()->get(['room_id', 'amount'])->toArray();

    $result = app(ReinstateUnit::class)($setup['actor'], $setup['unit'], $case === 'blank reason' ? '   ' : 'Reason');

    expect($result->refusal)->toBe($refusal)
        ->and($setup['unit']->fresh()->status)->toBe($status)
        ->and($setup['unit']->nights()->get(['room_id', 'amount'])->toArray())->toBe($nights)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    ['confirmed', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['checked out', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['blank reason', UnitStayResult::REFUSAL_REASON_REQUIRED],
    ['no business date', UnitStayResult::REFUSAL_NO_BUSINESS_DATE],
    ['stay ended', UnitStayResult::REFUSAL_STAY_ENDED],
    ['sold out', UnitStayResult::REFUSAL_NOT_AVAILABLE],
    ['no rate', 'rate_missing'],
]);
