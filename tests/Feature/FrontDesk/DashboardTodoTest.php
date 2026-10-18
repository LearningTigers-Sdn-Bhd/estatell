<?php

use App\Enums\HousekeepingStatus;
use App\Enums\PermissionEnum;
use App\Features\Boats;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\UnitBoatTrip;
use App\Models\User;
use App\Services\Dashboard\DashboardTodo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/** @param list<PermissionEnum> $permissions */
function todoProperty(array $permissions = [PermissionEnum::ReservationsView]): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create(['time_zone' => 'Asia/Kuala_Lumpur']);
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-14']);
    $user = User::factory()->for($property)->withPermissions($permissions)->create();
    setPermissionsTeamId($property->id);

    return [$property, $user];
}

function todoUnit(Property $property, array $attributes = []): ReservationUnit
{
    $factory = ReservationUnit::factory()->for(Reservation::factory()->for($property)->create());
    $factory = match ($attributes['status'] ?? 'confirmed') {
        'checked_in' => $factory->checkedIn(),
        'checked_out' => $factory->checkedOut(),
        'cancelled' => $factory->cancelled(),
        default => $factory,
    };

    return $factory->create([
        'arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16', ...$attributes,
    ]);
}

test('todo is deferred independently and is not sent without reservation access', function () {
    [$property, $user] = todoProperty();
    $this->actingAs($user)->get(route('dashboard', $property->code))
        ->assertInertia(fn (Assert $page) => $page->missing('todo')->loadDeferredProps('todo', fn (Assert $page) => $page
            ->where('todo.rows', [])->where('todo.total', 0)->where('todo.date', '2026-10-14')));

    $blind = User::factory()->for($property)->withPermissions([PermissionEnum::StaffView])->create();
    $this->actingAs($blind)->get(route('dashboard', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('todo', null)->where('housekeeping', null));
});

test('todo orders overdue arrivals and departures using the property clock and readiness rules', function () {
    [$property, $user] = todoProperty([PermissionEnum::ReservationsView, PermissionEnum::FrontDeskCheckIn, PermissionEnum::FrontDeskCheckOut]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 04:00:00 UTC'));
    $overdue = todoUnit($property, ['status' => 'checked_in', 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'departure_time' => '11:00']);
    $arrival = todoUnit($property, ['arrival_time' => '14:00']);
    $early = todoUnit($property, ['arrival_time' => '13:00']);
    $departure = todoUnit($property, ['status' => 'checked_in', 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'departure_time' => '15:00']);
    todoUnit($property, ['late_arrival_expected' => true]);
    todoUnit($property, ['arrival_date' => '2026-10-15']);
    todoUnit($property, ['status' => 'cancelled']);
    todoUnit(Property::factory()->live()->create());

    $this->actingAs($user)->get(route('dashboard', $property->code))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('todo', fn (Assert $page) => $page
            ->where('todo.total', 4)
            ->where('todo.rows.0.kind', 'overdue')->where('todo.rows.0.action.unitId', $overdue->id)
            ->where('todo.rows.1.action.unitId', $early->id)->where('todo.rows.2.action.unitId', $arrival->id)
            ->where('todo.rows.3.kind', 'departure')->where('todo.rows.3.action.unitId', $departure->id)
            ->where('todo.rows.0.action.dialog', 'check-out')->where('todo.rows.1.action.dialog', 'check-in')));
});

test('groups use check in all or the group check out page and viewers get View booking', function () {
    [$property, $user] = todoProperty([PermissionEnum::ReservationsView, PermissionEnum::FrontDeskCheckIn, PermissionEnum::FrontDeskCheckOut]);
    $arrival = todoUnit($property);
    todoUnit($property, ['reservation_id' => $arrival->reservation_id]);
    $departure = todoUnit($property, ['status' => 'checked_in', 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    todoUnit($property, ['reservation_id' => $departure->reservation_id, 'status' => 'checked_in', 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'departure_time' => '23:00']);

    $rows = app(DashboardTodo::class)->forDate($property, CarbonImmutable::parse('2026-10-14'), $user)->rows;
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->action->kind)->toBe('link')
        ->and($rows[0]->action->url)->toBe(route('reservations.check-out', [$property->code, $departure->reservation_id], false))
        ->and($rows[1]->detail)->toBe('2 rooms')
        ->and($rows[1]->action->dialog)->toBe('check-in-all');

    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();
    $viewRows = app(DashboardTodo::class)->forDate($property, CarbonImmutable::parse('2026-10-14'), $viewer)->rows;
    expect($viewRows[0]->action->label)->toBe('View booking')->and($viewRows[1]->action->kind)->toBe('link');
});

test('day use hours and folio warnings appear without duplicating audit rules', function () {
    [$property, $user] = todoProperty();
    ReservationUnit::factory()->for(Reservation::factory()->for($property)->create())->dayUse('2026-10-14')->create();
    $departed = todoUnit($property, ['status' => 'checked_out', 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    Folio::factory()->for($property)->forUnit($departed)->create(['reservation_id' => $departed->reservation_id]);

    $rows = app(DashboardTodo::class)->forDate($property, CarbonImmutable::parse('2026-10-14'), $user)->rows;
    expect($rows)->toHaveCount(2)->and($rows[0]->detail)->toBe('Day use 10:00–14:00')
        ->and($rows[1]->kind)->toBe('folio')->and($rows[1]->action->label)->toBe('Review folio')->and($rows[1]->action->icon)->toBe('receipt');
});

test('a departure is overdue after midnight even while the business date is unchanged', function () {
    [$property, $user] = todoProperty();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 00:00:00 UTC'));
    todoUnit($property, ['status' => 'checked_in', 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'departure_time' => '15:00']);

    $rows = app(DashboardTodo::class)->forDate($property, CarbonImmutable::parse('2026-10-14'), $user)->rows;
    expect($rows)->toHaveCount(1)->and($rows[0]->kind)->toBe('overdue');
});

test('no boat is feature gated and groups only confirmed arrivals today or tomorrow without an inbound trip', function () {
    [$property, $user] = todoProperty();
    $missing = todoUnit($property, ['arrival_date' => '2026-10-15']);
    todoUnit($property, ['reservation_id' => $missing->reservation_id, 'arrival_date' => '2026-10-15']);
    UnitBoatTrip::factory()->out()->own()->create(['property_id' => $property->id, 'reservation_unit_id' => $missing->id]);
    $own = todoUnit($property);
    UnitBoatTrip::factory()->own()->create(['property_id' => $property->id, 'reservation_unit_id' => $own->id]);
    $charter = todoUnit($property);
    UnitBoatTrip::factory()->charter()->create(['property_id' => $property->id, 'reservation_unit_id' => $charter->id]);
    todoUnit($property, ['arrival_date' => '2026-10-16', 'departure_date' => '2026-10-17']);
    todoUnit($property, ['status' => 'checked_in']);
    todoUnit(Property::factory()->live()->create());

    expect(collect(app(DashboardTodo::class)->forDate($property, CarbonImmutable::parse('2026-10-14'), $user)->rows)->where('kind', 'boat'))->toHaveCount(0);
    Feature::for($property)->activate(Boats::class);
    $rows = collect(app(DashboardTodo::class)->forDate($property, CarbonImmutable::parse('2026-10-14'), $user)->rows)->where('kind', 'boat')->values();
    expect($rows)->toHaveCount(1)->and($rows[0]->detail)->toBe('2 rooms')->and($rows[0]->when)->toBe('Tomorrow')
        ->and($rows[0]->action->label)->toBe('Set boats')->and($rows[0]->action->icon)->toBe('ship')->and($rows[0]->action->url)->toBe(route('reservations.show', [$property->code, $missing->reservation_id], false));
});

test('the dashboard dialog loader needs reservation access but not night audit access and scopes its reservation', function () {
    [$property, $user] = todoProperty([PermissionEnum::ReservationsView, PermissionEnum::FrontDeskCheckIn]);
    $unit = todoUnit($property);
    $foreign = todoUnit(Property::factory()->live()->create());
    $this->actingAs($user)->getJson(route('dashboard.reservation', [$property->code, $unit->reservation_id]))
        ->assertOk()->assertJsonPath('reservation.id', $unit->reservation_id)->assertJsonPath('units.0.actions.0.key', 'check-in');
    $this->getJson(route('dashboard.reservation', [$property->code, $foreign->reservation_id]))->assertNotFound();
    $blind = User::factory()->for($property)->withPermissions([PermissionEnum::StaffView])->create();
    $this->actingAs($blind)->getJson(route('dashboard.reservation', [$property->code, $unit->reservation_id]))->assertForbidden();
    $this->getJson(route('dashboard.reservation', [$foreign->property->code, $foreign->reservation_id]))->assertNotFound();
    auth()->logout();
    $this->get(route('dashboard.reservation', [$property->code, $unit->reservation_id]))->assertRedirect(route('login'));
});

test('housekeeping counts active workable rooms from the board and requires its own permission', function () {
    [$property, $user] = todoProperty([PermissionEnum::HousekeepingView]);
    $dirty = Room::factory()->for($property)->create();
    $dirty->status->update(['status' => HousekeepingStatus::Dirty]);
    $clean = Room::factory()->for($property)->create();
    $clean->status->update(['status' => HousekeepingStatus::Clean]);
    $inspected = Room::factory()->for($property)->create();
    $inspected->status->update(['status' => HousekeepingStatus::Inspected]);
    $blocked = Room::factory()->for($property)->create();
    RoomBlock::factory()->for($property)->create(['room_id' => $blocked->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-15']);
    Room::factory()->for($property)->archived()->create();
    Room::factory()->create();

    $this->actingAs($user)->get(route('dashboard', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('todo', null)->where('tiles', null)->missing('housekeeping')
            ->loadDeferredProps('housekeeping', fn (Assert $page) => $page->where('housekeeping', ['dirty' => 1, 'toInspect' => 1, 'blocked' => 1])));
});

test('todo limits the visible list and keeps a fixed query count as reservations grow', function () {
    [$property, $user] = todoProperty();
    $property->load('settings');
    Feature::for($property)->deactivate(Boats::class);
    todoUnit($property);
    todoUnit($property);
    $countQueries = function () use ($property, $user): int {
        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(DashboardTodo::class)->forDate($property, CarbonImmutable::parse('2026-10-14'), $user);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        Model::preventLazyLoading(false);

        return $count;
    };
    $countQueries();
    $count = $countQueries();
    foreach (range(1, 10) as $index) {
        todoUnit($property);
    }
    expect($countQueries())->toBe($count);
    $todo = app(DashboardTodo::class)->forDate($property, CarbonImmutable::parse('2026-10-14'), $user);
    expect($todo->rows)->toHaveCount(10)->and($todo->total)->toBe(12);
});
