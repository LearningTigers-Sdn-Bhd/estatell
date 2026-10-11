<?php

require_once __DIR__.'/Support/DayUseSetup.php';

use App\Actions\FrontDesk\ChangeUnitDates;
use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\NightAudit\PostScheduledAddOns;
use App\Actions\NightAudit\RunAuditFix;
use App\Actions\NightAudit\RunNightAudit;
use App\Actions\Reservations\AddUnitAddOn;
use App\Data\NightAudit\ReadinessGroupData;
use App\Data\Reservations\NewAddOnData;
use App\Enums\AuditCheck;
use App\Enums\AuditExceptionType;
use App\Enums\AuditFix;
use App\Enums\AuditStep;
use App\Enums\AuditTrigger;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\UnitAddOn;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\ReadinessAdvice;
use App\Services\NightAudit\ReadinessItem;

/**
 * A live property trading on the day use date with one Confirmed day use unit (10:00 for 4 hours) and a 25.00 item.
 *
 * @return array<string, mixed>
 */
function dayUseAuditDesk(): array
{
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $setup['property']->forceFill(['status' => 'live'])->save();
    BusinessDate::factory()->create(['property_id' => $setup['property']->id, 'date' => $setup['date']->toDateString()]);

    $reservation = createDayUseReservation($setup, [dayUseLine($setup, [])])->reservation;
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '25.00']);

    return [...$setup, 'reservation' => $reservation, 'unit' => $reservation->units()->sole(), 'item' => $item];
}

/**
 * @param  array<string, mixed>  $setup
 */
function dayUseAuditRun(array $setup): void
{
    app(RunNightAudit::class)($setup['property'], AuditTrigger::Manual, $setup['user'], true, 'test');
}

test('an add-on added to a checked in day use posts at once, so the check-out and the audit never lose it', function () {
    $setup = dayUseAuditDesk();
    app(CheckInUnit::class)($setup['user'], $setup['unit']);

    $result = app(AddUnitAddOn::class)($setup['user'], $setup['unit']->fresh(), NewAddOnData::from(['chargeItemId' => $setup['item']->id]));
    $addOn = $result->addOns[0]->fresh();

    expect($result->isRefused())->toBeFalse()
        ->and($addOn->folio_line_id)->not->toBeNull()
        ->and($addOn->service_date->toDateString())->toBe($setup['date']->toDateString())
        ->and(FolioLine::query()->where('idempotency_key', 'addon:'.$addOn->id)->count())->toBe(1);

    app(PostScheduledAddOns::class)($setup['user'], $setup['property'], $setup['date']);

    expect(FolioLine::query()->where('idempotency_key', 'addon:'.$addOn->id)->count())->toBe(1);
});

test('an add-on to a confirmed day use waits for check-in', function () {
    $setup = dayUseAuditDesk();

    $result = app(AddUnitAddOn::class)($setup['user'], $setup['unit'], NewAddOnData::from(['chargeItemId' => $setup['item']->id]));

    expect($result->addOns[0]->folio_line_id)->toBeNull()
        ->and(FolioLine::query()->count())->toBe(0);
});

test('an add-on that cannot post is refused and leaves no row', function () {
    $setup = dayUseAuditDesk();
    app(CheckInUnit::class)($setup['user'], $setup['unit']);
    $setup['item']->transactionCode->forceFill(['kind' => TransactionKind::Payment])->save();
    $rows = UnitAddOn::query()->count();
    $lines = FolioLine::query()->count();

    $result = app(AddUnitAddOn::class)($setup['user'], $setup['unit']->fresh(), NewAddOnData::from(['chargeItemId' => $setup['item']->id]));

    expect($result->isRefused())->toBeTrue()
        ->and(UnitAddOn::query()->count())->toBe($rows)
        ->and(FolioLine::query()->count())->toBe($lines);
});

test('a day use left checked in is never extended, whatever the policy', function () {
    $setup = dayUseAuditDesk();
    $setup['property']->settings->forceFill(['overdue_departure_policy' => OverdueDeparturePolicy::ExtendOneNight])->save();
    app(CheckInUnit::class)($setup['user'], $setup['unit']);

    dayUseAuditRun($setup);

    $unit = $setup['unit']->fresh();
    $exception = $setup['property']->nightAudits()->latest('id')->first()->exceptions()->sole();

    expect($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->departure_date->toDateString())->toBe($setup['date']->toDateString())
        ->and($unit->nights()->count())->toBe(0)
        ->and($exception->message)->toBe('day_use_not_extended');
});

test('the Extend fix refuses a day use and the sheet does not offer it', function () {
    $setup = dayUseAuditDesk();
    $setup['property']->settings->forceFill(['overdue_departure_policy' => OverdueDeparturePolicy::ExtendOneNight])->save();
    app(CheckInUnit::class)($setup['user'], $setup['unit']);

    $result = app(RunAuditFix::class)($setup['user'], $setup['property']->refresh(), $setup['reservation'], AuditFix::ExtendOneNight);
    $item = collect(app(AuditReadiness::class)->items($setup['property']))->firstWhere('check', AuditCheck::DepartureNotCheckedOut);
    $group = ReadinessGroupData::fromItems([$item], $setup['property'], app(ReadinessAdvice::class), true, true);

    expect($result->refusals)->toBe([$setup['unit']->id => 'day_use_not_extended'])
        ->and($setup['unit']->fresh()->departure_date->toDateString())->toBe($setup['date']->toDateString())
        ->and(collect($group->actions)->pluck('label')->all())->not->toContain('Extend 1 night')
        ->and($group->consequence)->toContain('never extended');
});

test('a confirmed day use shows its hours in Not checked in', function () {
    $setup = dayUseAuditDesk();

    $item = collect(app(AuditReadiness::class)->items($setup['property']))->firstWhere('check', AuditCheck::ArrivalNotCheckedIn);

    expect($item->detail)->toBe('Day use · 10:00–14:00')
        ->and($item->dayUse)->toBeTrue();
});

test('the dates of a day use cannot change', function () {
    $setup = dayUseAuditDesk();
    app(CheckInUnit::class)($setup['user'], $setup['unit']);

    $result = app(ChangeUnitDates::class)($setup['user'], $setup['unit']->fresh(), $setup['date'], $setup['date']->addDay());

    expect($result->refusal)->toBe('day_use_unit')
        ->and($setup['unit']->fresh()->departure_date->toDateString())->toBe($setup['date']->toDateString());
});

test('a folio item with no unit builds its sheet row for a user who may check in and out', function () {
    $setup = dayUseAuditDesk();
    $item = new ReadinessItem(AuditCheck::MasterNotBilled, $setup['reservation']->id, null, $setup['reservation']->reference, null, 'M-1 · MYR 10.00 owed', folioId: 1);

    $group = ReadinessGroupData::fromItems([$item], $setup['property'], app(ReadinessAdvice::class), true, true);

    expect($group->check)->toBe(AuditCheck::MasterNotBilled)
        ->and(collect($group->actions)->pluck('label')->all())->toBe(['Open folio']);
});

test('the sheet offers Extend 1 night only on a row with a unit that can be extended', function () {
    $setup = dayUseAuditDesk();
    $row = fn (bool $canExtend) => new ReadinessItem(AuditCheck::DepartureNotCheckedOut, $setup['reservation']->id, $setup['unit']->id, 'REF', null, '10 Oct → 11 Oct', canExtend: $canExtend);
    $labels = fn (bool $canExtend): array => collect(ReadinessGroupData::fromItems([$row($canExtend)], $setup['property'], app(ReadinessAdvice::class), true, true)->actions)->pluck('label')->all();

    expect($labels(true))->toContain('Extend 1 night')
        ->and($labels(false))->not->toContain('Extend 1 night')
        ->and($labels(false))->toContain('Check out');
});

test('a run resolves an exception whose unit has been checked out since', function () {
    $setup = dayUseAuditDesk();
    $unit = $setup['unit'];
    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subHours(3), 'checked_out_at' => now()])->save();
    $earlier = NightAudit::factory()->completed()->create(['property_id' => $setup['property']->id]);
    $exception = NightAuditException::factory()->create(['night_audit_id' => $earlier->id, 'reservation_unit_id' => $unit->id, 'step' => AuditStep::ResolveOverdueDepartures, 'type' => AuditExceptionType::OverdueDeparture, 'message' => 'overdue_departure_blocked']);

    dayUseAuditRun($setup);

    expect($exception->fresh()->resolved_at)->not->toBeNull();
});
