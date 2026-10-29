<?php

use App\Actions\NightAudit\PostRoomCharges;
use App\Enums\ChargingUnit;
use App\Enums\FolioStatus;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Stand-in for an audit of an earlier business date that could not charge one night.
 */
$earlierAudit = function (string $date) {
    $businessDate = BusinessDate::query()->where('property_id', $this->property->id)->whereDate('date', $date)->first()
        ?? BusinessDate::factory()->closed()->create(['property_id' => $this->property->id, 'date' => $date]);

    return NightAudit::query()->where('business_date_id', $businessDate->id)->first()
        ?? NightAudit::factory()->completed()->create(['property_id' => $this->property->id, 'business_date_id' => $businessDate->id]);
};

/**
 * A unit in house on a Dive Package (2 adults, 12 to 15 Oct, MYR 1,760.00 in all) whose even split of 586.67 a night is
 * less than its inclusions on the night of 12 Oct, which the audit of that day could not charge.
 *
 * @param  list<string>  $chargedNights  The nights, besides 12 Oct, that are charged already.
 */
$packageStay = function (array $chargedNights) use ($earlierAudit) {
    $roomType = RoomType::query()->where('property_id', $this->property->id)->where('name', 'Standard Double')->firstOrFail();
    $plan = RatePlan::factory()->for($this->property)->package(nights: 3)->create(['name' => 'Dive Package']);
    RatePlanInclusion::factory()->for($plan)->create(['charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '100.00']);
    RatePlanInclusion::factory()->for($plan)->create(['charging_unit' => ChargingUnit::PerGuest, 'quantity' => 2, 'allocated_amount' => '180.00']);

    $unit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $this->property->id]))->withNights()->checkedIn()->create([
        'property_id' => $this->property->id, 'room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id,
        'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-15', 'adults' => 2,
    ]);
    $room = Room::factory()->for($this->property)->create(['room_type_id' => $roomType->id, 'number' => '501']);

    foreach ($unit->nights()->orderBy('date')->get() as $index => $night) {
        $amount = ['586.67', '586.67', '586.66'][$index];
        $night->forceFill(['room_id' => $room->id, 'base_amount' => $amount, 'amount' => $amount])->save();
    }

    foreach ($chargedNights as $date) {
        expect(app(PostRoomCharges::class)(auth()->user(), $unit, CarbonImmutable::parse($date))->isRefused())->toBeFalse();
    }

    NightAuditException::factory()->create([
        'night_audit_id' => $earlierAudit->call($this, '2026-10-12')->id,
        'reservation_unit_id' => $unit->id,
        'message' => 'exceeds_night_amount',
    ]);

    return $unit;
};

/**
 * A unit in house whose night of 12 Oct could not be charged because its folio was closed.
 */
$closedFolioStay = function () use ($earlierAudit) {
    $roomType = RoomType::query()->where('property_id', $this->property->id)->where('name', 'Standard Double')->firstOrFail();
    $unit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $this->property->id]))->withNights()->checkedIn()->create([
        'property_id' => $this->property->id, 'room_type_id' => $roomType->id,
        'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-15',
    ]);
    $room = Room::factory()->for($this->property)->create(['room_type_id' => $roomType->id, 'number' => '502']);
    $unit->nights()->update(['room_id' => $room->id]);

    expect(app(PostRoomCharges::class)(auth()->user(), $unit, CarbonImmutable::parse('2026-10-13'))->isRefused())->toBeFalse();
    Folio::query()->where('reservation_unit_id', $unit->id)->update(['status' => FolioStatus::Closed]);

    NightAuditException::factory()->create([
        'night_audit_id' => $earlierAudit->call($this, '2026-10-12')->id,
        'reservation_unit_id' => $unit->id,
        'message' => 'folio_not_open',
    ]);

    return $unit;
};

/**
 * Move the clock to 01:00 on 15 Oct in Kuala Lumpur, so the sheet has no "Not over yet" alert.
 */
$afterMidnight = function () {
    $now = CarbonImmutable::parse('2026-10-14 17:00', 'UTC');

    CarbonImmutable::setTestNow($now);
    Carbon::setTestNow($now);
};

test('the sheet lists what an earlier audit left', function () use ($packageStay, $closedFolioStay, $afterMidnight) {
    $packageStay->call($this, ['2026-10-13']);
    $closedFolioStay->call($this);
    $afterMidnight->call($this);

    $page = $this->open(route('dashboard', $this->property), height: 1400)
        ->click('Run Night Audit')
        ->waitForText('Left from an earlier audit');

    $this->shoot($page, 'earlier-audit-issues-sheet', badges: ['Fix price', 'Retry', 'Dismiss', 'Open'], frame: '[role="dialog"]');
});

test('fix price with the nights left', function () use ($packageStay, $afterMidnight) {
    $packageStay->call($this, ['2026-10-13']);
    $afterMidnight->call($this);

    $page = $this->open(route('dashboard', $this->property), height: 1400)
        ->click('Run Night Audit')
        ->click('Fix price')
        ->waitForText('Spread again over the nights not charged yet');

    $this->shoot($page, 'earlier-audit-issues-fix-price', frame: '[role="dialog"]');
});

test('fix price over the whole stay', function () use ($packageStay, $afterMidnight) {
    $packageStay->call($this, ['2026-10-13', '2026-10-14']);
    $afterMidnight->call($this);

    $page = $this->open(route('dashboard', $this->property), height: 1400)
        ->click('Run Night Audit')
        ->click('Fix price')
        ->waitForText('Spread again over the whole stay');

    $this->shoot($page, 'earlier-audit-issues-fix-price-whole-stay', frame: '[role="dialog"]');
});

test('dismiss with a reason', function () use ($packageStay, $afterMidnight) {
    $packageStay->call($this, ['2026-10-13']);
    $afterMidnight->call($this);

    $page = $this->open(route('dashboard', $this->property), height: 1400)
        ->click('Run Night Audit')
        ->click('Dismiss')
        ->waitForText('Dismiss audit exception')
        ->type('#dismiss-exception-reason', 'Charged by hand on the folio.');

    $this->shoot($page, 'earlier-audit-issues-dismiss', frame: '[role="alertdialog"], [role="dialog"]');
});
