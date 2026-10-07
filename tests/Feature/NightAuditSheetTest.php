<?php

use App\Enums\AuditTrigger;
use App\Enums\MissedArrivalPolicy;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\PermissionEnum;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Jobs\RunNightAuditJob;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property trading on 2026-10-12 and a user who has only the given permissions.
 *
 * @param  list<PermissionEnum>  $permissions
 * @return array{property: Property, user: User}
 */
function sheetSetup(array $permissions = [PermissionEnum::NightAuditRun, PermissionEnum::ReservationsManage]): array
{
    test()->travelTo(now()->setDate(2026, 10, 13)->setTime(2, 0));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $user = User::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Audit']);
    $role->givePermissionTo(array_map(fn (PermissionEnum $permission): string => $permission->value, $permissions));
    setPermissionsTeamId($property->id);
    $user->assignRole($role);

    return ['property' => $property, 'user' => $user];
}

function sheetUnit(Property $property, array $attributes = []): ReservationUnit
{
    return ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights()->checkedIn()->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-14',
        ...$attributes,
    ]);
}

test('the sheet lists the date, the blockers and the warnings as JSON', function () {
    $setup = sheetSetup();
    $property = $setup['property'];
    $unit = sheetUnit($property);
    ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-13']);

    $this->actingAs($setup['user'])->getJson(route('night-audit.show', $property->code))
        ->assertOk()
        ->assertJsonPath('date', '2026-10-12')
        ->assertJsonPath('nextDate', '2026-10-13')
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('dateEnded', true)
        ->assertJsonPath('canForce', false)
        ->assertJsonPath('summary', 'Fix 1 thing before you run the audit.')
        ->assertJsonCount(1, 'blockers')
        ->assertJsonPath('blockers.0.check', 'in_house_without_room')
        ->assertJsonPath('blockers.0.headline', 'No room or bed tonight')
        ->assertJsonPath('blockers.0.reference', $unit->reservation->reference)
        ->assertJsonPath('blockers.0.actions.0.label', 'Assign a room')
        ->assertJsonPath('blockers.0.actions.0.url', route('night-audit.fix', [$property->code, $unit->reservation_id, 'assign'], absolute: false))
        ->assertJsonCount(1, 'warnings')
        ->assertJsonPath('warnings.0.check', 'arrival_not_checked_in')
        ->assertJsonPath('warnings.0.consequence', 'If you leave it: kept as a late arrival. Nothing is charged.')
        ->assertJsonPath('warnings.0.actions.0.kind', 'link')
        ->assertJsonPath('warnings.0.actions.1.kind', 'post')
        ->assertJsonPath('audit', null);
});

test('a group booking is one row that opens to its units, and the policy decides the words', function () {
    $setup = sheetSetup();
    $property = $setup['property'];
    $property->settings->update(['missed_arrival_policy' => MissedArrivalPolicy::MarkNoShow, 'overdue_departure_policy' => OverdueDeparturePolicy::ExtendOneNight]);
    TransactionCode::factory()->for($property)->system(SystemTransactionCode::NoShowRevenue)->create();
    RoomRevenuePolicy::factory()->for($property)->fixed('60.00')->forCode(SystemTransactionCode::NoShowRevenue, PolicyScope::WholeStay)->create();
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    ReservationUnit::factory()->count(3)->for($reservation)->withNights()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    $single = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights()->checkedIn()->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);
    $single->nights()->update(['room_id' => Room::factory()->for($property)->create()->id]);

    $json = $this->actingAs($setup['user'])->getJson(route('night-audit.show', $property->code))->assertOk()->json();
    $group = collect($json['warnings'])->firstWhere('check', 'arrival_not_checked_in');
    $departure = collect($json['warnings'])->firstWhere('check', 'departure_not_checked_out');

    expect($json['warnings'])->toHaveCount(2)
        ->and($group['headline'])->toBe('3 units — not checked in')
        ->and($group['units'])->toHaveCount(3)
        ->and($group['consequence'])->toBe('If you leave it: marked no-show, and the no-show fee is charged.')
        ->and($group['actions'][1]['label'])->toBe('Mark all no-show')
        ->and($group['actions'][1]['confirm'])->toBe('Mark 3 units as no-show? The no-show fee of MYR 180.00 is charged.')
        ->and($group['units'][0]['actions'])->toHaveCount(1)
        ->and($group['units'][0]['actions'][0]['label'])->toBe('Mark no-show')
        ->and($group['units'][0]['actions'][0]['unitId'])->toBe($group['units'][0]['unitId'])
        ->and($departure['units'])->toBe([])
        ->and($departure['consequence'])->toBe('If you leave it: one more night is charged and the departure moves a day.');
});

test('an exception left by an earlier audit says why in plain words', function () {
    $setup = sheetSetup();
    $unit = sheetUnit($setup['property']);
    $unit->nights()->update(['room_id' => Room::factory()->for($setup['property'])->create()->id]);
    $audit = NightAudit::factory()->completed()->create(['business_date_id' => BusinessDate::factory()->closed()->create(['property_id' => $setup['property']->id, 'date' => '2026-10-11'])->id]);
    NightAuditException::factory()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $unit->id, 'message' => 'folio_not_open']);

    $this->actingAs($setup['user'])->getJson(route('night-audit.show', $setup['property']->code))
        ->assertJsonPath('warnings.0.check', 'carried_exception')
        ->assertJsonPath('warnings.0.reasons.0', 'The folio was closed, so the night could not be charged.');
});

test('the sheet needs night-audit.run and a live property', function () {
    $setup = sheetSetup([PermissionEnum::ReservationsView]);

    $this->actingAs($setup['user'])->getJson(route('night-audit.show', $setup['property']->code))->assertForbidden();
});

test('a run is queued for the current user, and refused while a blocker is open', function () {
    Queue::fake();
    $setup = sheetSetup();
    $property = $setup['property'];
    $unit = sheetUnit($property);

    $this->actingAs($setup['user'])->postJson(route('night-audit.run', $property->code))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('form');
    Queue::assertNothingPushed();

    $unit->nights()->update(['room_id' => Room::factory()->for($property)->create()->id]);

    $this->actingAs($setup['user'])->postJson(route('night-audit.run', $property->code))->assertAccepted();
    Queue::assertPushed(RunNightAuditJob::class, fn (RunNightAuditJob $job): bool => $job->property->is($property)
        && $job->trigger === AuditTrigger::Manual
        && $job->actor->is($setup['user'])
        && $job->force === false);
});

test('a forced run needs night-audit.force and a reason', function () {
    Queue::fake();
    $setup = sheetSetup();
    sheetUnit($setup['property']);

    $this->actingAs($setup['user'])->postJson(route('night-audit.run', $setup['property']->code), ['force' => true, 'reason' => 'Fix in the morning'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('force');

    $forcer = sheetSetup([PermissionEnum::NightAuditRun, PermissionEnum::NightAuditForce]);
    sheetUnit($forcer['property']);

    $this->actingAs($forcer['user'])->postJson(route('night-audit.run', $forcer['property']->code), ['force' => true, 'reason' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
    Queue::assertNothingPushed();

    $this->actingAs($forcer['user'])->postJson(route('night-audit.run', $forcer['property']->code), ['force' => true, 'reason' => 'Fix in the morning'])->assertAccepted();
    Queue::assertPushed(RunNightAuditJob::class, fn (RunNightAuditJob $job): bool => $job->force && $job->forceReason === 'Fix in the morning');
});

test('the queued job runs the audit', function () {
    $setup = sheetSetup();
    $unit = sheetUnit($setup['property']);
    $unit->nights()->update(['room_id' => Room::factory()->for($setup['property'])->create()->id]);

    RunNightAuditJob::dispatchSync($setup['property'], AuditTrigger::Manual, $setup['user']);

    expect(NightAudit::query()->sole()->run_by)->toBe($setup['user']->id)
        ->and($setup['property']->businessDates()->count())->toBe(2);
});

test('the sheet follows the audit once it has run', function () {
    $setup = sheetSetup();
    $unit = sheetUnit($setup['property']);
    $unit->nights()->update(['room_id' => Room::factory()->for($setup['property'])->create()->id]);
    RunNightAuditJob::dispatchSync($setup['property'], AuditTrigger::Manual, $setup['user']);

    $this->actingAs($setup['user'])->getJson(route('night-audit.show', $setup['property']->code))
        ->assertJsonPath('date', '2026-10-13')
        ->assertJsonPath('audit.date', '2026-10-12')
        ->assertJsonPath('audit.status', 'completed')
        ->assertJsonCount(7, 'audit.steps')
        ->assertJsonPath('audit.steps.2.label', 'Post room charges')
        ->assertJsonPath('audit.steps.2.status', 'completed');
});

/**
 * The property of `sheetSetup` with a room type, a plan with rates from 2026-10-11, and the no-show policy, for the fixes.
 *
 * @return array{property: Property, user: User, roomType: RoomType, plan: RatePlan}
 */
function fixSetup(): array
{
    $setup = sheetSetup();
    $property = $setup['property'];
    TransactionCode::factory()->for($property)->system(SystemTransactionCode::NoShowRevenue)->create();
    TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();
    RoomRevenuePolicy::factory()->for($property)->fixed('60.00')->forCode(SystemTransactionCode::NoShowRevenue, PolicyScope::WholeStay)->create();
    $roomType = RoomType::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    for ($day = 0; $day < 6; $day++) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => today()->subDays(2)->addDays($day)->toDateString(), 'amount' => '120.00']);
    }

    return [...$setup, 'roomType' => $roomType, 'plan' => $plan];
}

function fixUrl(Property $property, ReservationUnit $unit, string $fix): string
{
    return route('night-audit.fix', [$property->code, $unit->reservation_id, $fix]);
}

test('Assign gives every in-house unit of the reservation a free room, or one unit when asked', function () {
    $setup = fixSetup();
    $property = $setup['property'];
    $rooms = Room::factory()->count(2)->for($property)->create(['room_type_id' => $setup['roomType']->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    [$first, $second] = ReservationUnit::factory()->count(2)->for($reservation)->withNights()->checkedIn()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);

    $this->actingAs($setup['user'])->postJson(fixUrl($property, $first, 'assign'), ['unit_id' => $first->id])->assertOk()->assertJsonPath('done', 1);

    expect($first->nights()->whereNull('room_id')->count())->toBe(0)
        ->and($second->nights()->whereNotNull('room_id')->count())->toBe(0);

    $this->postJson(fixUrl($property, $first, 'assign'))->assertOk()->assertJsonPath('done', 1);

    expect($second->nights()->pluck('room_id')->unique()->all())->toBe([$rooms->firstWhere('id', '!=', $first->nights()->value('room_id'))->id]);
});

test('Assign with no free room says so and fixes nothing', function () {
    $setup = fixSetup();
    $unit = sheetUnit($setup['property'], ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id]);

    $this->actingAs($setup['user'])->postJson(fixUrl($setup['property'], $unit, 'assign'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.form.0', 'No room or bed is free for the whole stay. Move the unit from its reservation.');
});

test('Mark no-show marks the listed units and posts the fee, as the user', function () {
    $setup = fixSetup();
    $property = $setup['property'];
    $property->settings->update(['missed_arrival_policy' => MissedArrivalPolicy::KeepAsLateArrival]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $units = ReservationUnit::factory()->count(2)->for($reservation)->withNights()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    $future = ReservationUnit::factory()->for($reservation)->withNights()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => '2026-10-13', 'departure_date' => '2026-10-14']);

    $this->actingAs($setup['user'])->postJson(fixUrl($property, $units[0], 'no-show'))->assertOk()->assertJsonPath('done', 2);

    expect($units->every(fn ($unit): bool => $unit->fresh()->status === UnitStatus::NoShow))->toBeTrue()
        ->and($future->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'no_show:%')->whereNull('parent_line_id')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'reservation.unit_no_show')->first()->causer_id)->toBe($setup['user']->id);
});

test('Extend 1 night moves a unit that departs today, and refuses one past its departure', function () {
    $setup = fixSetup();
    $property = $setup['property'];
    $today = sheetUnit($property, ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);
    $today->nights()->update(['room_id' => Room::factory()->for($property)->create(['room_type_id' => $setup['roomType']->id])->id]);
    $stale = sheetUnit($property, ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => '2026-10-09', 'departure_date' => '2026-10-11']);
    $stale->nights()->update(['room_id' => Room::factory()->for($property)->create(['room_type_id' => $setup['roomType']->id])->id]);

    $this->actingAs($setup['user'])->postJson(fixUrl($property, $today, 'extend'))->assertOk()->assertJsonPath('done', 1);
    $this->postJson(fixUrl($property, $stale, 'extend'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.form.0', 'The unit is past its departure date. Check it out instead.');

    expect($today->fresh()->departure_date->toDateString())->toBe('2026-10-13')
        ->and($stale->fresh()->departure_date->toDateString())->toBe('2026-10-11');
});

test('a fix that finds nothing, in another property, or without reservations.manage is refused', function () {
    $setup = fixSetup();
    $unit = sheetUnit($setup['property']);
    $unit->nights()->update(['room_id' => Room::factory()->for($setup['property'])->create()->id]);
    $foreign = sheetUnit(Property::factory()->live()->create());

    $this->actingAs($setup['user'])->postJson(fixUrl($setup['property'], $unit, 'assign'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.form.0', 'There is nothing left to fix here. The list has been refreshed.');
    $this->postJson(route('night-audit.fix', [$setup['property']->code, $foreign->reservation_id, 'assign']))->assertNotFound();
    $this->postJson(route('night-audit.fix', [$setup['property']->code, $unit->reservation_id, 'unknown']))->assertNotFound();

    $viewer = sheetSetup([PermissionEnum::NightAuditRun]);
    $other = sheetUnit($viewer['property']);

    $this->actingAs($viewer['user'])->postJson(fixUrl($viewer['property'], $other, 'assign'))->assertForbidden();
});

test('the sidebar shows Run Night Audit as a sheet action to users with night-audit.run', function () {
    $setup = sheetSetup();
    $without = sheetSetup([PermissionEnum::ReservationsView]);
    $find = fn (Assert $page) => $page->where('navigation.contexts.0.groups.1.items.0.action', 'night-audit')
        ->where('navigation.contexts.0.groups.1.items.0.title', 'Run Night Audit')
        ->where('navigation.contexts.0.groups.1.items.0.href', route('night-audit.show', $setup['property']->code, absolute: false));

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code))->assertInertia(function (Assert $page) use ($find) {
        $find($page);
    });
    $this->actingAs($without['user'])->get(route('dashboard', $without['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('navigation.contexts.0.groups.1.label', 'Reservations'));
});

test('the sheet says the date has not ended, and a run is refused until it has or is forced', function () {
    Queue::fake();
    $setup = sheetSetup([PermissionEnum::NightAuditRun, PermissionEnum::NightAuditForce]);
    $setup['property']->settings->update(['time_zone' => 'UTC']);
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(23, 0));

    $this->actingAs($setup['user'])->getJson(route('night-audit.show', $setup['property']->code))
        ->assertJsonPath('dateEnded', false)
        ->assertJsonPath('canForce', true);
    $this->postJson(route('night-audit.run', $setup['property']->code))
        ->assertUnprocessable()
        ->assertJsonPath('errors.form.0', 'The business date has not ended yet. Force close if you are sure.');
    Queue::assertNothingPushed();

    $this->postJson(route('night-audit.run', $setup['property']->code), ['force' => true, 'reason' => 'Closing early'])->assertAccepted();
    Queue::assertPushed(RunNightAuditJob::class);
});

test('check-in and check-out open Dialogs over the sheet for staff who may use them, and link otherwise', function () {
    $setup = sheetSetup([PermissionEnum::NightAuditRun, PermissionEnum::ReservationsManage, PermissionEnum::FrontDeskCheckIn, PermissionEnum::FrontDeskCheckOut]);
    $property = $setup['property'];
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    ReservationUnit::factory()->count(2)->for($reservation)->withNights()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    $single = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    $out = ReservationUnit::factory()->count(2)->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights()->checkedIn()->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);
    $out->each(fn ($unit) => $unit->nights()->update(['room_id' => Room::factory()->for($property)->create()->id]));

    $warnings = collect($this->actingAs($setup['user'])->getJson(route('night-audit.show', $property->code))->json('warnings'));
    $group = $warnings->firstWhere('reservationId', $reservation->id);
    $one = $warnings->firstWhere('reservationId', $single->reservation_id);
    $departures = $warnings->firstWhere('reservationId', $out[0]->reservation_id);

    expect($group['actions'][0])->toMatchArray(['kind' => 'dialog', 'dialog' => 'check-in-all', 'label' => 'Check in all', 'unitId' => null])
        ->and($group['units'][0]['actions'][0])->toMatchArray(['kind' => 'dialog', 'dialog' => 'check-in'])
        ->and($one['actions'][0])->toMatchArray(['kind' => 'dialog', 'dialog' => 'check-in', 'unitId' => $single->id])
        ->and($departures['openUnits'])->toBeTrue()
        ->and(collect($departures['actions'])->pluck('label')->all())->toBe(['Extend 1 night'])
        ->and($departures['units'][0]['actions'][0])->toMatchArray(['kind' => 'dialog', 'dialog' => 'check-out'])
        ->and($group['actions'][0]['url'])->toBe(route('night-audit.reservation', [$property->code, $reservation->id], absolute: false));

    $plain = sheetSetup();
    ReservationUnit::factory()->count(2)->for(Reservation::factory()->create(['property_id' => $plain['property']->id]))->withNights()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    $row = $this->actingAs($plain['user'])->getJson(route('night-audit.show', $plain['property']->code))->json('warnings.0');

    expect($row['actions'][0]['kind'])->toBe('link')
        ->and($row['units'][0]['actions'])->toHaveCount(1);
});

test('the dialog data is the reservation, its units with their actions, and the payment options', function () {
    $setup = sheetSetup([PermissionEnum::NightAuditRun, PermissionEnum::ReservationsView, PermissionEnum::ReservationsManage, PermissionEnum::FrontDeskCheckIn, PermissionEnum::PaymentRecord]);
    $property = $setup['property'];
    $unit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    $foreign = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => Property::factory()->live()->create()->id]))->create();

    $this->actingAs($setup['user'])->getJson(route('night-audit.reservation', [$property->code, $unit->reservation_id]))
        ->assertOk()
        ->assertJsonPath('reservation.id', $unit->reservation_id)
        ->assertJsonPath('units.0.id', $unit->id)
        ->assertJsonPath('units.0.actions.0.key', 'check-in')
        ->assertJsonPath('canAssignRoom', true)
        ->assertJsonPath('canWaiveFees', false)
        ->assertJsonStructure(['paymentOptions' => ['methods', 'purposes'], 'currency']);
    $this->getJson(route('night-audit.reservation', [$property->code, $foreign->reservation_id]))->assertNotFound();

    $blind = sheetSetup([PermissionEnum::NightAuditRun]);
    $other = sheetUnit($blind['property']);

    $this->actingAs($blind['user'])->getJson(route('night-audit.reservation', [$blind['property']->code, $other->reservation_id]))->assertForbidden();
});
