<?php

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\FrontDesk\CheckOutUnit;
use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\PermissionEnum;
use App\Enums\RegistrationCardStatus;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RegistrationCard;
use App\Models\RegistrationCardTerms;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\UnitAddOn;
use App\Models\UnitGuest;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date (today) and a Confirmed unit that arrives today for two nights, with
 * room 101 assigned on every night.
 *
 * @param  array<string, mixed>  $unit
 * @return array{property: Property, actor: User, reservation: Reservation, unit: ReservationUnit, room: Room}
 */
function stayDeskSetup(array $unit = [], bool $assignRoom = true): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $roomType = RoomType::factory()->for($property)->create();
    $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '101']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $roomType->id,
        'arrival_date' => today()->toDateString(),
        'departure_date' => today()->addDays(2)->toDateString(),
        ...$unit,
    ]);

    if ($assignRoom) {
        $unit->nights()->update(['room_id' => $room->id]);
    }

    return ['property' => $property, 'actor' => User::factory()->create(['property_id' => $property->id]), 'reservation' => $reservation, 'unit' => $unit, 'room' => $room];
}

/**
 * A CheckedIn unit that arrived two days ago and departs today, with room 101 on every night.
 *
 * @return array{property: Property, actor: User, reservation: Reservation, unit: ReservationUnit, room: Room}
 */
function stayDeskInHouse(): array
{
    return stayDeskSetup([
        'status' => UnitStatus::CheckedIn,
        'checked_in_at' => now()->subDays(2),
        'arrival_date' => today()->subDays(2)->toDateString(),
        'departure_date' => today()->toDateString(),
    ]);
}

function stayDeskCheckIn(array $setup, bool $withoutCard = false, ?string $reason = null): UnitStayResult
{
    return app(CheckInUnit::class)($setup['actor'], $setup['unit'], $withoutCard, $reason);
}

function stayDeskCheckOut(array $setup): UnitStayResult
{
    return app(CheckOutUnit::class)($setup['actor'], $setup['unit']);
}

function stayDeskGuest(array $setup, ?string $nationality, bool $primary = true): UnitGuest
{
    $guest = UnitGuest::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'is_primary' => $primary]);
    $guest->guest->update(['nationality_code' => $nationality]);

    return $guest;
}

function stayDeskFolio(array $setup, string $amount): Folio
{
    $folio = Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => $amount, 'unit_amount' => $amount, 'posting_date' => today()->toDateString()]);

    return $folio;
}

test('check-in sets the status and time, syncs the reservation and logs the event', function () {
    $setup = stayDeskSetup();

    $result = stayDeskCheckIn($setup);

    $unit = $setup['unit']->fresh();
    $log = Activity::query()->where('event', 'reservation.unit_checked_in')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->checked_in_at)->not->toBeNull()
        ->and($setup['reservation']->fresh()->status)->toBe(ReservationStatus::InHouse)
        ->and($log->causer_id)->toBe($setup['actor']->id)
        ->and($log->subject_id)->toBe($setup['reservation']->id)
        ->and($log->properties['room_id'])->toBe($setup['room']->id);
});

test('check-in is refused and writes nothing when the unit cannot be checked in', function (string $case, string $refusal) {
    $setup = match ($case) {
        'checked out' => stayDeskSetup(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()]),
        'cancelled' => stayDeskSetup(['status' => UnitStatus::Cancelled]),
        'before arrival' => stayDeskSetup(['arrival_date' => today()->addDay()->toDateString(), 'departure_date' => today()->addDays(3)->toDateString()]),
        'after departure' => stayDeskSetup(['arrival_date' => today()->subDays(3)->toDateString(), 'departure_date' => today()->subDay()->toDateString()]),
        'no room' => stayDeskSetup(assignRoom: false),
        'no business date' => tap(stayDeskSetup(), fn (array $setup) => BusinessDate::query()->where('property_id', $setup['property']->id)->delete()),
    };

    $status = $setup['unit']->status;
    $checkedInAt = $setup['unit']->fresh()->checked_in_at;
    $result = stayDeskCheckIn($setup);

    expect($result->refusal)->toBe($refusal)
        ->and($setup['unit']->fresh()->status)->toBe($status)
        ->and($setup['unit']->fresh()->checked_in_at?->toDateTimeString())->toBe($checkedInAt?->toDateTimeString())
        ->and(Activity::query()->count())->toBe(0);
})->with([
    ['checked out', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['cancelled', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['before arrival', UnitStayResult::REFUSAL_ARRIVAL_NOT_REACHED],
    ['after departure', UnitStayResult::REFUSAL_STAY_ENDED],
    ['no room', UnitStayResult::REFUSAL_NO_ROOM_ASSIGNED],
    ['no business date', UnitStayResult::REFUSAL_NO_BUSINESS_DATE],
]);

test('a second check-in of the same unit is refused', function () {
    $setup = stayDeskSetup();
    stayDeskCheckIn($setup);

    expect(stayDeskCheckIn($setup)->refusal)->toBe(UnitStayResult::REFUSAL_INVALID_STATUS)
        ->and(Activity::query()->where('event', 'reservation.unit_checked_in')->count())->toBe(1);
});

test('a bed unit checks in on its bed', function () {
    $setup = stayDeskSetup(assignRoom: false);
    $bed = Bed::factory()->create(['room_id' => $setup['room']->id]);
    $setup['unit']->nights()->update(['bed_id' => $bed->id]);

    expect(stayDeskCheckIn($setup)->isRefused())->toBeFalse()
        ->and(Activity::query()->where('event', 'reservation.unit_checked_in')->sole()->properties['bed_id'])->toBe($bed->id);
});

test('the registration card rule needs a signed card unless the user passes it', function () {
    $setup = stayDeskSetup();
    $setup['property']->settings->update(['card_required_for_check_in' => true]);
    $guest = stayDeskGuest($setup, 'MY');

    expect(stayDeskCheckIn($setup)->refusal)->toBe(UnitStayResult::REFUSAL_CARD_MISSING)
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed);

    RegistrationCard::factory()->create([
        'unit_guest_id' => $guest->id,
        'status' => RegistrationCardStatus::Signed,
        'signed_at' => now(),
        'terms_id' => RegistrationCardTerms::factory()->create()->id,
        'snapshot' => ['guest' => 'signed'],
        'number' => 'RC-1',
    ]);

    expect(stayDeskCheckIn($setup)->isRefused())->toBeFalse();
});

test('check-in without a card is allowed when the user passes the card rule', function () {
    $setup = stayDeskSetup();
    $setup['property']->settings->update(['card_required_for_check_in' => true]);
    stayDeskGuest($setup, 'MY');

    $result = stayDeskCheckIn($setup, withoutCard: true);

    expect($result->isRefused())->toBeFalse()
        ->and(Activity::query()->where('event', 'reservation.unit_checked_in')->sole()->properties['without_card'])->toBeTrue();
});

test('the card rule is skipped when the property does not require a card', function () {
    $setup = stayDeskSetup();
    stayDeskGuest($setup, 'MY');

    expect(stayDeskCheckIn($setup)->isRefused())->toBeFalse();
});

test('a foreign-only tax needs the nationality of the primary guest', function () {
    $setup = stayDeskSetup();
    Tax::factory()->for($setup['property'])->create(['foreign_guests_only' => true]);
    stayDeskGuest($setup, null);

    expect(stayDeskCheckIn($setup)->refusal)->toBe(UnitStayResult::REFUSAL_NATIONALITY_REQUIRED)
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed);

    $setup['unit']->guests()->first()->guest->update(['nationality_code' => 'SG']);

    expect(stayDeskCheckIn($setup)->isRefused())->toBeFalse();
});

test('a unit with no primary guest uses the booker nationality', function () {
    $setup = stayDeskSetup();
    Tax::factory()->for($setup['property'])->create(['foreign_guests_only' => true]);
    $setup['reservation']->booker->update(['nationality_code' => null]);

    expect(stayDeskCheckIn($setup)->refusal)->toBe(UnitStayResult::REFUSAL_NATIONALITY_REQUIRED);

    $setup['reservation']->booker->update(['nationality_code' => 'SG']);

    expect(stayDeskCheckIn($setup)->isRefused())->toBeFalse();
});

test('the nationality rule ignores a disabled foreign-only tax and an all-guests tax', function () {
    $setup = stayDeskSetup();
    Tax::factory()->for($setup['property'])->disabled()->create(['foreign_guests_only' => true]);
    Tax::factory()->for($setup['property'])->create(['foreign_guests_only' => false]);
    stayDeskGuest($setup, null);

    expect(stayDeskCheckIn($setup)->isRefused())->toBeFalse();
});

test('a company over its credit limit is refused unless the user gives a reason, which is logged', function () {
    $setup = stayDeskSetup();
    $company = Company::factory()->for($setup['property'])->create(['credit_limit' => '100.00']);
    $setup['reservation']->update(['company_id' => $company->id]);
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id, 'payer_company_id' => $company->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '150.00', 'unit_amount' => '150.00', 'posting_date' => today()->toDateString()]);

    expect(stayDeskCheckIn($setup)->refusal)->toBe(UnitStayResult::REFUSAL_CREDIT_LIMIT_EXCEEDED)
        ->and(stayDeskCheckIn($setup, reason: '  ')->refusal)->toBe(UnitStayResult::REFUSAL_CREDIT_LIMIT_EXCEEDED)
        ->and(Activity::query()->count())->toBe(0);

    $result = stayDeskCheckIn($setup, reason: 'Approved by the owner');
    $override = Activity::query()->where('event', 'credit.overridden')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($override->properties['reason'])->toBe('Approved by the owner')
        ->and($override->properties['action'])->toBe('check_in');
});

test('an unbilled master of a departed reservation counts against the company at check-in', function () {
    $setup = stayDeskSetup();
    $company = Company::factory()->for($setup['property'])->create(['credit_limit' => '100.00']);
    $setup['reservation']->update(['company_id' => $company->id]);
    $departed = Reservation::factory()->create(['property_id' => $setup['property']->id, 'status' => ReservationStatus::Completed, 'company_id' => $company->id]);
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $departed->id, 'payer_company_id' => $company->id]);
    FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '150.00', 'unit_amount' => '150.00', 'posting_date' => today()->toDateString()]);

    expect(stayDeskCheckIn($setup)->refusal)->toBe(UnitStayResult::REFUSAL_CREDIT_LIMIT_EXCEEDED);
});

test('a company under its limit checks in without an override', function () {
    $setup = stayDeskSetup();
    $company = Company::factory()->for($setup['property'])->create(['credit_limit' => '1000.00']);
    $setup['reservation']->update(['company_id' => $company->id]);

    expect(stayDeskCheckIn($setup)->isRefused())->toBeFalse()
        ->and(Activity::query()->where('event', 'credit.overridden')->count())->toBe(0);
});

test('check-in posts undated add-ons and leaves dated ones for the night audit', function () {
    $setup = stayDeskSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '25.00']);
    $undated = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $item->id]);
    $dated = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $item->id, 'service_date' => today()->addDay()->toDateString()]);

    $result = stayDeskCheckIn($setup);

    $line = FolioLine::query()->where('idempotency_key', 'addon:'.$undated->id)->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($undated->fresh()->folio_line_id)->toBe($line->id)
        ->and($line->amount)->toBe('25.00')
        ->and($dated->fresh()->folio_line_id)->toBeNull();
});

test('an add-on that cannot post refuses the check-in and rolls the status back', function () {
    $setup = stayDeskSetup();
    $item = ChargeItem::factory()->inactive()->create(['property_id' => $setup['property']->id]);
    UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $item->id]);

    $result = stayDeskCheckIn($setup);

    expect($result->refusal)->toBe('charge_not_found')
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($setup['unit']->fresh()->checked_in_at)->toBeNull()
        ->and($setup['reservation']->fresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and(Activity::query()->count())->toBe(0)
        ->and(FolioLine::query()->count())->toBe(0);
});

test('check-out sets the status and time, marks the room Dirty, syncs the reservation and logs the event', function () {
    $setup = stayDeskInHouse();
    $setup['reservation']->update(['status' => ReservationStatus::InHouse]);
    RoomStatus::query()->where('room_id', $setup['room']->id)->update(['status' => HousekeepingStatus::Inspected]);

    $result = stayDeskCheckOut($setup);

    $unit = $setup['unit']->fresh();
    $status = RoomStatus::query()->where('room_id', $setup['room']->id)->sole();
    $log = Activity::query()->where('event', 'reservation.unit_checked_out')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->checked_out_at)->not->toBeNull()
        ->and($status->status)->toBe(HousekeepingStatus::Dirty)
        ->and($status->last_changed_by)->toBe($setup['actor']->id)
        ->and($setup['reservation']->fresh()->status)->toBe(ReservationStatus::Completed)
        ->and($log->properties['room_id'])->toBe($setup['room']->id);
});

test('a bed unit marks the room of its bed Dirty on check-out', function () {
    $setup = stayDeskInHouse();
    $bed = Bed::factory()->create(['room_id' => $setup['room']->id]);
    $setup['unit']->nights()->update(['room_id' => null, 'bed_id' => $bed->id]);
    RoomStatus::query()->where('room_id', $setup['room']->id)->update(['status' => HousekeepingStatus::Clean]);

    stayDeskCheckOut($setup);

    expect(RoomStatus::query()->where('room_id', $setup['room']->id)->sole()->status)->toBe(HousekeepingStatus::Dirty);
});

test('check-out is refused and writes nothing when the unit cannot be checked out', function (string $case, string $refusal) {
    $setup = match ($case) {
        'confirmed' => stayDeskSetup(),
        'early' => stayDeskSetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]),
        'owes' => tap(stayDeskInHouse(), fn (array $setup) => stayDeskFolio($setup, '80.00')),
        'deposit credit' => tap(stayDeskInHouse(), fn (array $setup) => stayDeskFolio($setup, '-100.00')),
        'no business date' => tap(stayDeskInHouse(), fn (array $setup) => BusinessDate::query()->where('property_id', $setup['property']->id)->delete()),
    };

    $status = $setup['unit']->status;
    $result = stayDeskCheckOut($setup);

    expect($result->refusal)->toBe($refusal)
        ->and($setup['unit']->fresh()->status)->toBe($status)
        ->and($setup['unit']->fresh()->checked_out_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
})->with([
    ['confirmed', UnitStayResult::REFUSAL_INVALID_STATUS],
    ['early', UnitStayResult::REFUSAL_BEFORE_DEPARTURE_DATE],
    ['owes', UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED],
    ['deposit credit', UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED],
    ['no business date', UnitStayResult::REFUSAL_NO_BUSINESS_DATE],
]);

test('check-out passes with no folio, a settled folio, or a deposit that was refunded', function (?string $first, ?string $second) {
    $setup = stayDeskInHouse();

    if ($first !== null) {
        $folio = stayDeskFolio($setup, $first);

        if ($second !== null) {
            FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => $second, 'unit_amount' => $second, 'posting_date' => today()->toDateString()]);
        }
    }

    expect(stayDeskCheckOut($setup)->isRefused())->toBeFalse();
})->with([
    'no folio' => [null, null],
    'settled' => ['80.00', '-80.00'],
    'deposit refunded' => ['-100.00', '100.00'],
]);

test('a Master folio balance does not block a unit check-out', function () {
    $setup = stayDeskInHouse();
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '300.00', 'unit_amount' => '300.00', 'posting_date' => today()->toDateString()]);

    expect(stayDeskCheckOut($setup)->isRefused())->toBeFalse();
});

test('check-out closes the unit folio at 0, issues its invoice number and logs it', function () {
    $setup = stayDeskInHouse();
    $folio = stayDeskFolio($setup, '80.00');
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '-80.00', 'unit_amount' => '-80.00', 'posting_date' => today()->toDateString()]);

    $result = stayDeskCheckOut($setup);

    expect($result->isRefused())->toBeFalse()
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed)
        ->and($folio->fresh()->invoice_number)->not->toBeNull()
        ->and($folio->fresh()->closed_by)->toBe($setup['actor']->id)
        ->and(Activity::query()->where('event', 'folio.closed')->count())->toBe(1);
});

test('check-out leaves the Master and ThirdParty folios open', function () {
    $setup = stayDeskInHouse();
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $agent = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '300.00', 'unit_amount' => '300.00', 'posting_date' => today()->toDateString()]);

    expect(stayDeskCheckOut($setup)->isRefused())->toBeFalse()
        ->and($master->fresh()->status)->toBe(FolioStatus::Open)
        ->and($agent->fresh()->status)->toBe(FolioStatus::Open);
});

test('a refused check-out leaves the unit folio open', function () {
    $setup = stayDeskInHouse();
    $folio = stayDeskFolio($setup, '80.00');

    expect(stayDeskCheckOut($setup)->refusal)->toBe(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED)
        ->and($folio->fresh()->status)->toBe(FolioStatus::Open)
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Activity::query()->where('event', 'folio.closed')->count())->toBe(0);
});

test('a close that is refused rolls the check-out back as balance not settled', function () {
    $setup = stayDeskInHouse();
    $folio = Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $closer = Mockery::mock(CloseFolio::class);
    $closer->shouldReceive('__invoke')->andReturn(FolioStatusResult::refused(FolioStatusResult::REFUSAL_BALANCE_NOT_ZERO));
    app()->instance(CloseFolio::class, $closer);

    $result = app(CheckOutUnit::class)($setup['actor'], $setup['unit']);

    expect($result->refusal)->toBe(UnitStayResult::REFUSAL_BALANCE_NOT_SETTLED)
        ->and($setup['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($setup['unit']->fresh()->checked_out_at)->toBeNull()
        ->and($folio->fresh()->status)->toBe(FolioStatus::Open);
});

test('a group reservation stays in house until every unit is out', function () {
    $setup = stayDeskInHouse();
    $setup['reservation']->update(['status' => ReservationStatus::InHouse]);
    $other = ReservationUnit::factory()->for($setup['reservation'])->checkedIn()->create([
        'arrival_date' => today()->subDays(2)->toDateString(),
        'departure_date' => today()->toDateString(),
    ]);

    stayDeskCheckOut($setup);

    expect($setup['reservation']->fresh()->status)->toBe(ReservationStatus::InHouse);

    app(CheckOutUnit::class)($setup['actor'], $other);

    expect($setup['reservation']->fresh()->status)->toBe(ReservationStatus::Completed);
});

test('the front desk permissions go to the default roles', function () {
    $frontDesk = DefaultRoleEnum::FrontDesk->permissions();
    $manager = DefaultRoleEnum::Manager->permissions();

    expect($frontDesk)->toContain(PermissionEnum::FrontDeskCheckIn, PermissionEnum::FrontDeskCheckOut)
        ->and($frontDesk)->not->toContain(PermissionEnum::FrontDeskCheckInWithoutCard)
        ->and($manager)->toContain(PermissionEnum::FrontDeskCheckIn, PermissionEnum::FrontDeskCheckOut, PermissionEnum::FrontDeskCheckInWithoutCard)
        ->and(DefaultRoleEnum::Owner->permissions())->toContain(PermissionEnum::FrontDeskCheckInWithoutCard);
});
