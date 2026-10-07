<?php

use App\Enums\AuditTrigger;
use App\Enums\PermissionEnum;
use App\Jobs\RunNightAuditJob;
use App\Models\BusinessDate;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

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
        ->assertJsonPath('canForce', false)
        ->assertJsonCount(1, 'blockers')
        ->assertJsonPath('blockers.0.check', 'in_house_without_room')
        ->assertJsonPath('blockers.0.assignUrl', route('night-audit.units.assign', [$property->code, $unit->reservation_id, $unit->id], absolute: false))
        ->assertJsonCount(1, 'warnings')
        ->assertJsonPath('warnings.0.check', 'arrival_not_checked_in')
        ->assertJsonPath('warnings.0.assignUrl', null)
        ->assertJsonPath('audit', null);
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
        ->assertJsonCount(6, 'audit.steps')
        ->assertJsonPath('audit.steps.2.label', 'Post room charges')
        ->assertJsonPath('audit.steps.2.status', 'completed');
});

test('an in-house unit gets a free room from the sheet, and another property\'s unit is not found', function () {
    $setup = sheetSetup();
    $property = $setup['property'];
    $roomType = RoomType::factory()->for($property)->create();
    $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
    $unit = sheetUnit($property, ['room_type_id' => $roomType->id]);
    $other = Property::factory()->live()->create();
    $foreign = sheetUnit($other);

    $this->actingAs($setup['user'])->postJson(route('night-audit.units.assign', [$property->code, $unit->reservation_id, $unit->id]))
        ->assertOk()
        ->assertJsonPath('assigned', true);
    $this->postJson(route('night-audit.units.assign', [$property->code, $foreign->reservation_id, $foreign->id]))->assertNotFound();

    expect($unit->nights()->pluck('room_id')->unique()->all())->toBe([$room->id]);
});

test('assigning with no free room says so', function () {
    $setup = sheetSetup();
    $unit = sheetUnit($setup['property']);

    $this->actingAs($setup['user'])->postJson(route('night-audit.units.assign', [$setup['property']->code, $unit->reservation_id, $unit->id]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.form.0', 'No room or bed is free for the whole stay. Move the unit from its reservation.');
});

test('assigning needs reservations.manage', function () {
    $setup = sheetSetup([PermissionEnum::NightAuditRun]);
    $unit = sheetUnit($setup['property']);

    $this->actingAs($setup['user'])->postJson(route('night-audit.units.assign', [$setup['property']->code, $unit->reservation_id, $unit->id]))->assertForbidden();
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
