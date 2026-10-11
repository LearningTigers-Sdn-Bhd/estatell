<?php

use App\Actions\NightAudit\NightAuditResult;
use App\Actions\NightAudit\RunNightAudit;
use App\Enums\AuditCheck;
use App\Enums\AuditStatus;
use App\Enums\BusinessDateStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\ReadinessItem;
use Carbon\CarbonImmutable;

/**
 * A live property trading on 2026-10-12.
 */
function readinessProperty(): Property
{
    test()->travelTo(now()->setDate(2026, 10, 13)->setTime(2, 0));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);

    return $property;
}

function readinessUnit(Property $property, string $state, string $arrival, string $departure, array $attributes = []): ReservationUnit
{
    $factory = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights();

    return ($state === 'confirmed' ? $factory : $factory->{$state}())->create(['arrival_date' => $arrival, 'departure_date' => $departure, ...$attributes]);
}

/**
 * @return list<string>
 */
function readinessChecks(Property $property): array
{
    return array_map(fn ($item): string => $item->check->value, app(AuditReadiness::class)->items($property));
}

test('an in-house unit without a room or bed tonight is the only blocker', function () {
    $property = readinessProperty();
    $bare = readinessUnit($property, 'checkedIn', '2026-10-12', '2026-10-14');
    $assigned = readinessUnit($property, 'checkedIn', '2026-10-12', '2026-10-14');
    $assigned->nights()->update(['room_id' => Room::factory()->for($property)->create()->id]);
    readinessUnit($property, 'confirmed', '2026-10-12', '2026-10-14');

    $blockers = app(AuditReadiness::class)->blockers($property);

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0]->check)->toBe(AuditCheck::InHouseWithoutRoom)
        ->and($blockers[0]->reservationUnitId)->toBe($bare->id)
        ->and($blockers[0]->reference)->toBe($bare->reservation->reference)
        ->and($blockers[0]->detail)->toBe('12 Oct → 14 Oct')
        ->and(readinessChecks($property)[0])->toBe('in_house_without_room');
});

test('arrivals and departures that the date has reached are listed, and future or expected ones are not', function () {
    $property = readinessProperty();
    readinessUnit($property, 'confirmed', '2026-10-12', '2026-10-14');
    readinessUnit($property, 'confirmed', '2026-10-10', '2026-10-14');
    readinessUnit($property, 'confirmed', '2026-10-13', '2026-10-14');
    readinessUnit($property, 'confirmed', '2026-10-12', '2026-10-14', ['late_arrival_expected' => true]);
    readinessUnit($property, 'checkedIn', '2026-10-10', '2026-10-12')->nights()->update(['room_id' => Room::factory()->for($property)->create()->id]);
    readinessUnit($property, 'checkedIn', '2026-10-10', '2026-10-13')->nights()->update(['room_id' => Room::factory()->for($property)->create()->id]);

    $checks = array_count_values(readinessChecks($property));

    expect($checks)->toBe([AuditCheck::ArrivalNotCheckedIn->value => 2, AuditCheck::DepartureNotCheckedOut->value => 1]);
});

test('an open unit folio after check-out and an open exception from an earlier audit are listed', function () {
    $property = readinessProperty();
    $out = readinessUnit($property, 'checkedOut', '2026-10-09', '2026-10-11');
    Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $out->reservation_id, 'reservation_unit_id' => $out->id, 'type' => FolioType::Unit, 'status' => FolioStatus::Open]);
    $closedOut = readinessUnit($property, 'checkedOut', '2026-10-09', '2026-10-11');
    Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $closedOut->reservation_id, 'reservation_unit_id' => $closedOut->id, 'type' => FolioType::Unit, 'status' => FolioStatus::Closed]);

    $previous = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-11']);
    $audit = NightAudit::factory()->completed()->create(['business_date_id' => $previous->id]);
    NightAuditException::factory()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $out->id, 'message' => 'folio_not_open']);
    NightAuditException::factory()->resolved()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $out->id]);

    $items = app(AuditReadiness::class)->items($property);

    expect(array_map(fn ($item): string => $item->check->value, $items))->toBe([AuditCheck::FolioOpenAfterCheckOut->value, AuditCheck::CarriedException->value])
        ->and($items[1]->message)->toBe('folio_not_open');
});

/**
 * An Open folio of the type on the unit's reservation, with one posted line of the amount.
 */
function readinessFolio(Property $property, ReservationUnit $unit, FolioType $type, string $amount, array $attributes = []): Folio
{
    $folio = Folio::factory()->create([
        'property_id' => $property->id,
        'reservation_id' => $unit->reservation_id,
        'reservation_unit_id' => $type === FolioType::Unit ? $unit->id : null,
        'type' => $type,
        'status' => FolioStatus::Open,
        ...$attributes,
    ]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => $amount, 'unit_amount' => $amount, 'posting_date' => '2026-10-12']);

    return $folio;
}

/**
 * @return list<ReadinessItem>
 */
function readinessOf(Property $property, AuditCheck $check): array
{
    return array_values(array_filter(app(AuditReadiness::class)->items($property), fn ($item): bool => $item->check === $check));
}

test('a master still open with a balance after every unit has left is listed once for the reservation', function () {
    $property = readinessProperty();
    $company = Company::factory()->for($property)->create(['name' => 'Acme Sdn Bhd']);
    $out = readinessUnit($property, 'checkedOut', '2026-10-09', '2026-10-11');
    ReservationUnit::factory()->cancelled()->create(['reservation_id' => $out->reservation_id]);
    $master = readinessFolio($property, $out, FolioType::Master, '1200.00', ['payer_company_id' => $company->id]);
    $agent = readinessFolio($property, $out, FolioType::ThirdParty, '500.00');

    $items = readinessOf($property, AuditCheck::MasterNotBilled);

    expect($items)->toHaveCount(1)
        ->and($items[0]->reservationId)->toBe($out->reservation_id)
        ->and($items[0]->reservationUnitId)->toBeNull()
        ->and($items[0]->guest)->toBe('Acme Sdn Bhd')
        ->and($items[0]->folioId)->toBe($master->id)
        ->and($items[0]->detail)->toContain('2 folios')->toContain('1,700.00 owed');

    $agent->forceFill(['status' => FolioStatus::Closed])->save();

    expect(readinessOf($property, AuditCheck::MasterNotBilled)[0]->detail)->toContain('Master '.$master->number)->toContain('1,200.00 owed');
});

test('a master with no payer is listed with the booker, and the other cases are not', function (Closure $arrange) {
    $property = readinessProperty();
    $out = readinessUnit($property, 'checkedOut', '2026-10-09', '2026-10-11');
    $master = readinessFolio($property, $out, FolioType::Master, '300.00');
    $arrange($property, $out, $master);

    expect(readinessOf($property, AuditCheck::MasterNotBilled))->toBe([]);
})->with([
    'a unit still in house' => [fn (Property $property, ReservationUnit $out) => ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $out->reservation_id])],
    'a unit still to arrive' => [fn (Property $property, ReservationUnit $out) => ReservationUnit::factory()->create(['reservation_id' => $out->reservation_id])],
    'a balance of zero' => [fn (Property $property, ReservationUnit $out, Folio $master) => FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '-300.00', 'unit_amount' => '-300.00', 'posting_date' => '2026-10-12'])],
    'a closed folio' => [fn (Property $property, ReservationUnit $out, Folio $master) => $master->forceFill(['status' => FolioStatus::Closed])->save()],
    'a credit balance' => [fn (Property $property, ReservationUnit $out, Folio $master) => FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '-900.00', 'unit_amount' => '-900.00', 'posting_date' => '2026-10-12'])],
]);

test('a master with no payer names the booker, and a reservation where nobody checked out is not listed', function () {
    $property = readinessProperty();
    $out = readinessUnit($property, 'checkedOut', '2026-10-09', '2026-10-11');
    readinessFolio($property, $out, FolioType::Master, '300.00');
    $cancelled = readinessUnit($property, 'cancelled', '2026-10-09', '2026-10-11');
    readinessFolio($property, $cancelled, FolioType::Master, '80.00');

    $items = readinessOf($property, AuditCheck::MasterNotBilled);

    expect($items)->toHaveCount(1)
        ->and($items[0]->guest)->toBe($out->reservation->booker->full_name);
});

test('money held on a cancelled or no-show unit folio, or a master credit once everyone has left, is listed', function () {
    $property = readinessProperty();
    $cancelled = readinessUnit($property, 'cancelled', '2026-10-14', '2026-10-16');
    ReservationUnit::factory()->create(['reservation_id' => $cancelled->reservation_id]);
    readinessFolio($property, $cancelled, FolioType::Unit, '-250.00');
    $noShow = readinessUnit($property, 'noShow', '2026-10-12', '2026-10-13');
    readinessFolio($property, $noShow, FolioType::Unit, '-80.00');
    $completed = readinessUnit($property, 'checkedOut', '2026-10-09', '2026-10-11');
    $master = readinessFolio($property, $completed, FolioType::Master, '-400.00');

    $items = collect(readinessOf($property, AuditCheck::MoneyHeldOnClosedBooking))->keyBy('reservationId');

    expect($items)->toHaveCount(3)
        ->and($items[$cancelled->reservation_id]->detail)->toContain('250.00 to refund')
        ->and($items[$noShow->reservation_id]->detail)->toContain('80.00 to refund')
        ->and($items[$completed->reservation_id]->detail)->toBe('MYR 400.00 to refund · '.$master->number)
        ->and($items[$completed->reservation_id]->folioId)->toBe($master->id);

});

test('a credit is not listed while a unit is in house or to arrive, and a debit, a zero balance or a closed folio are not listed', function (Closure $arrange) {
    $property = readinessProperty();
    $out = readinessUnit($property, 'checkedOut', '2026-10-09', '2026-10-11');
    $master = readinessFolio($property, $out, FolioType::Master, '-400.00');
    $arrange($property, $out, $master);

    expect(readinessOf($property, AuditCheck::MoneyHeldOnClosedBooking))->toBe([]);
})->with([
    'a unit in house' => [fn (Property $property, ReservationUnit $out) => ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $out->reservation_id])],
    'a unit to arrive' => [fn (Property $property, ReservationUnit $out) => ReservationUnit::factory()->create(['reservation_id' => $out->reservation_id])],
    'a debit' => [fn (Property $property, ReservationUnit $out, Folio $master) => FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '900.00', 'unit_amount' => '900.00', 'posting_date' => '2026-10-12'])],
    'a zero balance' => [fn (Property $property, ReservationUnit $out, Folio $master) => FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '400.00', 'unit_amount' => '400.00', 'posting_date' => '2026-10-12'])],
    'a closed folio' => [fn (Property $property, ReservationUnit $out, Folio $master) => $master->forceFill(['status' => FolioStatus::Closed])->save()],
]);

test('the two folio checks are warnings: the gate and the blockers ignore them', function () {
    $property = readinessProperty();
    $out = readinessUnit($property, 'checkedOut', '2026-10-09', '2026-10-11');
    readinessFolio($property, $out, FolioType::Master, '300.00');
    $cancelled = readinessUnit($property, 'cancelled', '2026-10-14', '2026-10-16');
    readinessFolio($property, $cancelled, FolioType::Unit, '-250.00');

    $readiness = app(AuditReadiness::class);

    expect(readinessChecks($property))->toBe([AuditCheck::MasterNotBilled->value, AuditCheck::MoneyHeldOnClosedBooking->value])
        ->and($readiness->blockers($property))->toBe([])
        ->and($readiness->gate($property))->toBeNull()
        ->and(app(RunNightAudit::class)($property)->isCompleted())->toBeTrue();
});

test('another property\'s units and a property without a business date list nothing', function () {
    $property = readinessProperty();
    $other = Property::factory()->live()->create();
    readinessUnit($other, 'confirmed', '2026-10-12', '2026-10-14');

    expect(readinessChecks($property))->toBe([])
        ->and(readinessChecks(Property::factory()->create()))->toBe([]);
});

test('a run is refused while a blocker is open and writes nothing', function () {
    $property = readinessProperty();
    readinessUnit($property, 'checkedIn', '2026-10-12', '2026-10-14');

    $result = app(RunNightAudit::class)($property);

    expect($result->refusal)->toBe(NightAuditResult::REFUSAL_BLOCKED_BY_READINESS)
        ->and(NightAudit::query()->count())->toBe(0)
        ->and($property->businessDates()->sole()->status)->toBe(BusinessDateStatus::Open);
});

test('a forced run goes through a blocker', function () {
    $property = readinessProperty();
    readinessUnit($property, 'checkedIn', '2026-10-12', '2026-10-14');

    $result = app(RunNightAudit::class)($property, force: true, forceReason: 'Rooms are fixed in the morning');

    expect($result->isCompleted())->toBeTrue()
        ->and($result->audit->forced)->toBeTrue();
});

test('a failed audit resumes even while a blocker is open', function () {
    $property = readinessProperty();
    readinessUnit($property, 'checkedIn', '2026-10-12', '2026-10-14');
    $date = $property->businessDates()->sole();
    $date->update(['status' => BusinessDateStatus::Auditing]);
    NightAudit::factory()->failed()->create(['business_date_id' => $date->id]);

    $result = app(RunNightAudit::class)($property);

    expect($result->isRefused())->toBeFalse()
        ->and($result->audit->status)->toBe(AuditStatus::Completed);
});

test('a date that has not ended is refused unless the run is forced, and a date that ended is not', function () {
    $property = readinessProperty();
    $property->settings->update(['time_zone' => 'UTC']);
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(23, 30));

    expect(app(AuditReadiness::class)->businessDateEnded($property))->toBeFalse()
        ->and(app(RunNightAudit::class)($property)->refusal)->toBe(NightAuditResult::REFUSAL_BUSINESS_DATE_NOT_ENDED)
        ->and(NightAudit::query()->count())->toBe(0)
        ->and($property->businessDates()->sole()->status)->toBe(BusinessDateStatus::Open);

    $forced = app(RunNightAudit::class)($property, force: true, forceReason: 'Closing early for the night');

    expect($forced->isCompleted())->toBeTrue()
        ->and($forced->audit->forced)->toBeTrue();
});

test('the date ends at midnight in the property time zone', function () {
    $property = readinessProperty();
    $property->settings->update(['time_zone' => 'Asia/Kuala_Lumpur']);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:59:00', 'UTC'));
    $notEnded = app(AuditReadiness::class)->businessDateEnded($property);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 16:00:00', 'UTC'));

    expect($notEnded)->toBeFalse()
        ->and(app(AuditReadiness::class)->businessDateEnded($property))->toBeTrue();
});

test('only a unit that departs on the business date can be extended', function () {
    $property = readinessProperty();
    $room = fn () => ['room_id' => Room::factory()->for($property)->create()->id];
    $today = readinessUnit($property, 'checkedIn', '2026-10-10', '2026-10-12');
    $today->nights()->update($room());
    $earlier = readinessUnit($property, 'checkedIn', '2026-10-09', '2026-10-11');
    $earlier->nights()->update($room());

    $items = collect(app(AuditReadiness::class)->items($property))->where('check', AuditCheck::DepartureNotCheckedOut);

    expect($items->firstWhere('reservationUnitId', $today->id)->canExtend)->toBeTrue()
        ->and($items->firstWhere('reservationUnitId', $earlier->id)->canExtend)->toBeFalse();
});
