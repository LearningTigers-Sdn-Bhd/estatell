<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\NightAudit\NightAuditResult;
use App\Actions\NightAudit\RunNightAudit;
use App\Actions\NightAudit\Steps\AuditStepRunner;
use App\Actions\NightAudit\Steps\ResolveOverdueDepartures;
use App\Actions\NightAudit\Steps\StepOutcome;
use App\Actions\NightAudit\Steps\UpdateRoomStatuses;
use App\Enums\AuditExceptionType;
use App\Enums\AuditStatus;
use App\Enums\AuditStep;
use App\Enums\AuditStepStatus;
use App\Enums\AuditTrigger;
use App\Enums\BusinessDateStatus;
use App\Enums\FolioStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\MissedArrivalPolicy;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\NightAudit;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property trading on 2026-10-12 (the clock is 02:00 the next day) with the room revenue and no-show codes, a fixed
 * 60.00 no-show fee, one room type with two rooms and a plan with a 120.00 rate on every night from that date.
 *
 * @param  array<string, mixed>  $settings
 * @return array{property: Property, roomType: RoomType, plan: RatePlan, rooms: Collection<int, Room>}
 */
function auditSetup(array $settings = []): array
{
    test()->travelTo(now()->setDate(2026, 10, 13)->setTime(2, 0));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create([
        'missed_arrival_policy' => MissedArrivalPolicy::KeepAsLateArrival,
        'overdue_departure_policy' => OverdueDeparturePolicy::Block,
        ...$settings,
    ]);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();
    TransactionCode::factory()->for($property)->system(SystemTransactionCode::NoShowRevenue)->create();
    RoomRevenuePolicy::factory()->for($property)->fixed('60.00')->forCode(SystemTransactionCode::NoShowRevenue, PolicyScope::WholeStay)->create();

    $roomType = RoomType::factory()->for($property)->create();
    $rooms = Room::factory()->count(2)->for($property)->create(['room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    for ($day = 0; $day < 10; $day++) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => today()->subDay()->addDays($day)->toDateString(), 'amount' => '120.00']);
    }

    return ['property' => $property, 'roomType' => $roomType, 'plan' => $plan, 'rooms' => $rooms];
}

/**
 * One unit of the setup's room type in the given room (index into the setup rooms), with nights from arrival to departure.
 *
 * @param  array<string, mixed>  $attributes
 */
function auditUnit(array $setup, string $state, string $arrival, string $departure, ?int $room = 0, array $attributes = []): ReservationUnit
{
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $factory = ReservationUnit::factory()->for($reservation)->withNights();
    $unit = ($state === 'confirmed' ? $factory : $factory->{$state}())->create([
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        ...$attributes,
    ]);
    if ($room !== null) {
        $unit->nights()->update(['room_id' => $setup['rooms'][$room]->id]);
    }

    return $unit;
}

function runAudit(Property $property, array $arguments = []): NightAuditResult
{
    return app(RunNightAudit::class)($property, ...$arguments);
}

function nightLineCount(): int
{
    return FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->count();
}

test('a run posts the night, marks the room dirty, closes the date and opens the next one', function () {
    $setup = auditSetup();
    $unit = auditUnit($setup, 'checkedIn', '2026-10-12', '2026-10-14');
    $setup['rooms'][0]->status->forceFill(['status' => HousekeepingStatus::Clean])->save();

    $result = runAudit($setup['property'], [AuditTrigger::Manual]);

    $audit = $result->audit;
    $dates = $setup['property']->businessDates()->orderBy('date')->get();

    expect($result->isCompleted())->toBeTrue()
        ->and($audit->status)->toBe(AuditStatus::Completed)
        ->and($audit->completed_at)->not->toBeNull()
        ->and($audit->steps()->count())->toBe(7)
        ->and($audit->steps()->where('status', AuditStepStatus::Completed->value)->count())->toBe(7)
        ->and($audit->steps()->where('step', AuditStep::PostRoomCharges->value)->sole()->processed_count)->toBe(1)
        ->and($dates->pluck('status')->all())->toBe([BusinessDateStatus::Closed, BusinessDateStatus::Open])
        ->and($dates->last()->date->toDateString())->toBe('2026-10-13')
        ->and($dates->first()->closed_at)->not->toBeNull()
        ->and(nightLineCount())->toBe(1)
        ->and(FolioLine::query()->where('idempotency_key', 'night:'.$unit->nights()->orderBy('date')->first()->id)->sole()->posting_date->toDateString())->toBe('2026-10-12')
        ->and($setup['rooms'][0]->status()->first()->status)->toBe(HousekeepingStatus::Dirty)
        ->and(Activity::query()->where('event', 'night-audit.completed')->count())->toBe(1);
});

test('the next run audits the next date and leaves the closed one alone', function () {
    $setup = auditSetup();
    auditUnit($setup, 'checkedIn', '2026-10-12', '2026-10-14');

    $first = runAudit($setup['property']);
    $closedLines = nightLineCount();
    $early = runAudit($setup['property']);
    $this->travel(1)->day();
    $second = runAudit($setup['property']);

    expect($early->refusal)->toBe(NightAuditResult::REFUSAL_BUSINESS_DATE_NOT_ENDED)
        ->and($second->isCompleted())->toBeTrue()
        ->and($second->audit->id)->not->toBe($first->audit->id)
        ->and($second->audit->businessDate->date->toDateString())->toBe('2026-10-13')
        ->and($closedLines)->toBe(1)
        ->and(nightLineCount())->toBe(2)
        ->and($setup['property']->businessDates()->where('status', BusinessDateStatus::Closed->value)->count())->toBe(2);
});

test('a failed step stops the run and the next run resumes it without posting twice', function () {
    $setup = auditSetup();
    auditUnit($setup, 'checkedIn', '2026-10-12', '2026-10-14');
    app()->bind(UpdateRoomStatuses::class, fn () => new class implements AuditStepRunner
    {
        public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome
        {
            throw new RuntimeException('boom');
        }
    });

    $failed = runAudit($setup['property']);
    $audit = $failed->audit;
    $charges = $audit->steps()->where('step', AuditStep::PostRoomCharges->value)->sole();
    $chargesFinishedAt = $charges->finished_at;
    $step = $audit->steps()->where('step', AuditStep::UpdateRoomStatuses->value)->sole();

    expect($failed->isFailed())->toBeTrue()
        ->and($audit->fresh()->status)->toBe(AuditStatus::Failed)
        ->and($step->status)->toBe(AuditStepStatus::Failed)
        ->and($step->error_message)->toContain('boom')
        ->and($audit->steps()->where('step', AuditStep::CloseAndOpenNext->value)->exists())->toBeFalse()
        ->and($audit->businessDate->fresh()->status)->toBe(BusinessDateStatus::Auditing)
        ->and(nightLineCount())->toBe(1)
        ->and(Activity::query()->where('event', 'night-audit.failed')->count())->toBe(1);

    app()->bind(UpdateRoomStatuses::class, fn () => new UpdateRoomStatuses);
    $this->travel(5)->minutes();
    $resumed = runAudit($setup['property']);

    expect($resumed->isCompleted())->toBeTrue()
        ->and($resumed->audit->id)->toBe($audit->id)
        ->and(NightAudit::query()->count())->toBe(1)
        ->and(nightLineCount())->toBe(1)
        ->and($charges->fresh()->finished_at->equalTo($chargesFinishedAt))->toBeTrue()
        ->and($audit->steps()->where('status', AuditStepStatus::Completed->value)->count())->toBe(7)
        ->and($audit->businessDate->fresh()->status)->toBe(BusinessDateStatus::Closed);
});

test('a unit that cannot post becomes an exception and the other units still post', function () {
    $setup = auditSetup();
    auditUnit($setup, 'checkedIn', '2026-10-12', '2026-10-14', 0);
    $blocked = auditUnit($setup, 'checkedIn', '2026-10-12', '2026-10-14', 1);
    app(CreateFolio::class)($blocked->reservation, $blocked)->forceFill(['status' => FolioStatus::Closed])->save();

    $result = runAudit($setup['property']);
    $step = $result->audit->steps()->where('step', AuditStep::PostRoomCharges->value)->sole();
    $exception = $result->audit->exceptions()->sole();

    expect($result->isCompleted())->toBeTrue()
        ->and(nightLineCount())->toBe(1)
        ->and($step->processed_count)->toBe(1)
        ->and($step->failed_count)->toBe(1)
        ->and($exception->type)->toBe(AuditExceptionType::RoomCharge)
        ->and($exception->step)->toBe(AuditStep::PostRoomCharges)
        ->and($exception->reservation_unit_id)->toBe($blocked->id)
        ->and($exception->message)->toBe('folio_not_open')
        ->and($exception->resolved_at)->toBeNull();
});

test('an add-on that cannot post becomes an exception on its unit', function () {
    $setup = auditSetup();
    $unit = auditUnit($setup, 'checkedIn', '2026-10-12', '2026-10-14');
    UnitAddOn::factory()->for($unit, 'reservationUnit')->create(['service_date' => '2026-10-12']);
    app(CreateFolio::class)($unit->reservation, $unit)->forceFill(['status' => FolioStatus::Closed])->save();

    $result = runAudit($setup['property']);

    expect($result->audit->exceptions()->where('type', AuditExceptionType::AddOn->value)->sole()->reservation_unit_id)->toBe($unit->id)
        ->and($result->audit->steps()->where('step', AuditStep::PostScheduledAddOns->value)->sole()->failed_count)->toBe(1);
});

test('a missed arrival is marked no-show with its fee, unless a late arrival is expected', function () {
    $setup = auditSetup(['missed_arrival_policy' => MissedArrivalPolicy::MarkNoShow]);
    $missed = auditUnit($setup, 'confirmed', '2026-10-12', '2026-10-14', 0);
    $late = auditUnit($setup, 'confirmed', '2026-10-12', '2026-10-14', 1, ['late_arrival_expected' => true]);
    $tomorrow = auditUnit($setup, 'confirmed', '2026-10-13', '2026-10-14', null);

    $result = runAudit($setup['property']);

    expect($result->isCompleted())->toBeTrue()
        ->and($missed->fresh()->status)->toBe(UnitStatus::NoShow)
        ->and($late->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($tomorrow->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(FolioLine::query()->where('idempotency_key', 'no_show:'.$missed->id.':1')->exists())->toBeTrue()
        ->and($result->audit->steps()->where('step', AuditStep::ResolveMissedArrivals->value)->sole()->processed_count)->toBe(1);
});

test('the keep as late arrival policy leaves missed arrivals as they are', function () {
    $setup = auditSetup();
    $missed = auditUnit($setup, 'confirmed', '2026-10-12', '2026-10-14');

    runAudit($setup['property']);

    expect($missed->fresh()->status)->toBe(UnitStatus::Confirmed);
});

test('a missed arrival the no-show Action refuses becomes an exception', function () {
    $setup = auditSetup(['missed_arrival_policy' => MissedArrivalPolicy::MarkNoShow]);
    $missed = auditUnit($setup, 'confirmed', '2026-10-12', '2026-10-14');
    RoomRevenuePolicy::query()->where('property_id', $setup['property']->id)->delete();

    $result = runAudit($setup['property']);
    $exception = $result->audit->exceptions()->sole();

    expect($missed->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($exception->type)->toBe(AuditExceptionType::MissedArrival)
        ->and($exception->message)->toBe('policy_not_found');
});

test('an overdue departure is extended one night and the extra night posts', function () {
    $setup = auditSetup(['overdue_departure_policy' => OverdueDeparturePolicy::ExtendOneNight]);
    $overdue = auditUnit($setup, 'checkedIn', '2026-10-10', '2026-10-12');

    $result = runAudit($setup['property']);

    expect($result->isCompleted())->toBeTrue()
        ->and($overdue->fresh()->departure_date->toDateString())->toBe('2026-10-13')
        ->and($overdue->nights()->whereDate('date', '2026-10-12')->exists())->toBeTrue()
        ->and(nightLineCount())->toBe(1)
        ->and($result->audit->exceptions()->count())->toBe(0);
});

test('the block policy extends nothing and records each overdue unit', function () {
    $setup = auditSetup();
    $overdue = auditUnit($setup, 'checkedIn', '2026-10-10', '2026-10-12');

    $result = runAudit($setup['property']);
    $exception = $result->audit->exceptions()->sole();

    expect($overdue->fresh()->departure_date->toDateString())->toBe('2026-10-12')
        ->and($exception->type)->toBe(AuditExceptionType::OverdueDeparture)
        ->and($exception->message)->toBe(ResolveOverdueDepartures::MESSAGE_BLOCKED)
        ->and(nightLineCount())->toBe(0);
});

test('a unit past its departure date is never extended over nights that cannot post', function () {
    $setup = auditSetup(['overdue_departure_policy' => OverdueDeparturePolicy::ExtendOneNight]);
    $stale = auditUnit($setup, 'checkedIn', '2026-10-09', '2026-10-11');

    $result = runAudit($setup['property']);

    expect($stale->fresh()->departure_date->toDateString())->toBe('2026-10-11')
        ->and($result->audit->exceptions()->sole()->message)->toBe(ResolveOverdueDepartures::MESSAGE_PAST_DEPARTURE);
});

test('a forced run keeps its reason and a forced run without one is refused', function () {
    $setup = auditSetup();
    $actor = User::factory()->create(['property_id' => $setup['property']->id]);

    $refused = runAudit($setup['property'], [AuditTrigger::Manual, $actor, true, '  ']);
    $forced = runAudit($setup['property'], [AuditTrigger::Manual, $actor, true, 'Printer is down']);

    expect($refused->refusal)->toBe(NightAuditResult::REFUSAL_REASON_REQUIRED)
        ->and(NightAudit::query()->count())->toBe(1)
        ->and($forced->audit->forced)->toBeTrue()
        ->and($forced->audit->force_reason)->toBe('Printer is down')
        ->and($forced->audit->run_by)->toBe($actor->id)
        ->and($forced->audit->businessDate->closed_by)->toBe($actor->id);
});

test('a property without a business date or with a run in progress is refused', function () {
    $setup = auditSetup();
    $other = Property::factory()->create();
    $audit = NightAudit::factory()->create(['business_date_id' => $setup['property']->businessDates()->sole()->id]);
    $setup['property']->businessDates()->update(['status' => BusinessDateStatus::Auditing]);

    expect(runAudit($other)->refusal)->toBe(NightAuditResult::REFUSAL_NO_BUSINESS_DATE)
        ->and(runAudit($setup['property'])->refusal)->toBe(NightAuditResult::REFUSAL_ALREADY_RUNNING)
        ->and($audit->fresh()->status)->toBe(AuditStatus::Running);
});
