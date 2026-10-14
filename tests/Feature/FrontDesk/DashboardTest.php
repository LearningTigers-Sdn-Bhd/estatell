<?php

use App\Data\Reports\ReportFilters;
use App\Enums\PermissionEnum;
use App\Enums\PropertyStatus;
use App\Enums\TransactionKind;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Dashboard\DashboardTiles;
use App\Services\Reports\ArrivalsReport;
use App\Services\Reports\DeparturesReport;
use App\Services\Reports\RevenueReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

test('staff open their own property dashboard with the property prop', function () {
    $staff = User::factory()->create();
    $property = $staff->property;

    $this->actingAs($staff)
        ->get(route('dashboard', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('property', ['code' => $property->code, 'name' => $property->name, 'status' => 'live'])
        );

    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($property->id);
});

test('a lower-case property code in the URL resolves', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/p/'.strtolower($user->property->code).'/dashboard')
        ->assertOk();
});

test('staff get a 404 on another property', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard', Property::factory()->create()->code))
        ->assertNotFound();
});

test('an unknown property code is a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get('/p/ES99999999/dashboard')
        ->assertNotFound();
});

test('a non-numeric property code does not match the route', function () {
    $this->actingAs(User::factory()->create())
        ->get('/p/abc/dashboard')
        ->assertNotFound();
});

test('admins are sent to the view-as page from property pages', function () {
    $property = Property::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard', $property->code))
        ->assertRedirect(route('view-as.show', ['property' => $property->code, 'to' => "/p/{$property->code}/dashboard"]));
});

test('guests are sent to login from property pages', function () {
    $this->get(route('dashboard', Property::factory()->create()->code))
        ->assertRedirect(route('login'));
});

test('unverified staff are sent to verify their email', function () {
    $staff = User::factory()->unverified()->create();

    $this->actingAs($staff)
        ->get(route('dashboard', $staff->property->code))
        ->assertRedirect(route('verification.notice'));
});

test('the home redirect sends staff to their property and admins to admin', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('home.redirect'))
        ->assertRedirect(route('dashboard', $staff->property->code));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('home.redirect'))
        ->assertRedirect(route('admin.dashboard'));
});

test('the home redirect sends onboarding owners to Basics while other staff and live owners keep the dashboard', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $staff = User::factory()->for($owner->property)->create();
    $liveOwner = User::factory()->owner()->create();
    $liveOwner->property->update(['status' => PropertyStatus::Live, 'went_live_at' => now()]);

    $this->actingAs($owner)
        ->get(route('home.redirect'))
        ->assertRedirect(route('onboarding.basics.edit', $owner->property->code));

    $this->actingAs($staff)
        ->get(route('home.redirect'))
        ->assertRedirect(route('dashboard', $staff->property->code));

    $this->actingAs($liveOwner)
        ->get(route('home.redirect'))
        ->assertRedirect(route('dashboard', $liveOwner->property->code));
});

test('pages without a property in the URL share the staff user\'s property', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('property.code', $staff->property->code));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('property', null));
});

/**
 * A live property trading on the business date, and a user who has only the given permissions.
 *
 * @param  list<PermissionEnum>  $permissions
 * @return array{property: Property, user: User}
 */
function dashboardSetup(array $permissions = [PermissionEnum::ReservationsView, PermissionEnum::ReportsView], string $date = '2026-10-12', string $now = '2026-10-12 12:00'): array
{
    test()->travelTo(CarbonImmutable::parse($now));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => $date]);
    $user = User::factory()->for($property)->withPermissions($permissions)->create();

    return ['property' => $property, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function dashboardUnit(Property $property, string $arrival, string $departure, string $state = 'confirmed', array $attributes = []): ReservationUnit
{
    $factory = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights();

    $factory = match ($state) {
        'checkedIn' => $factory->checkedIn(),
        'checkedOut' => $factory->checkedOut(),
        'cancelled' => $factory->cancelled(),
        default => $factory,
    };

    return $factory->create(['arrival_date' => $arrival, 'departure_date' => $departure, ...$attributes]);
}

function dashboardLine(Property $property, TransactionCode $code, string $amount, string $date, TransactionKind $kind = TransactionKind::Charge): void
{
    FolioLine::factory()->create([
        'folio_id' => Folio::factory()->create(['property_id' => $property->id])->id,
        'transaction_code_id' => $code->id,
        'kind' => $kind,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
    ]);
}

test('there is no alert on a normal day', function () {
    $setup = dashboardSetup();

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('alert', null));
});

test('the alert says a failed audit did not finish', function () {
    $setup = dashboardSetup();
    $businessDate = BusinessDate::query()->where('property_id', $setup['property']->id)->firstOrFail();
    $businessDate->update(['status' => 'auditing']);
    NightAudit::factory()->failed()->create(['business_date_id' => $businessDate->id]);

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('alert.message', 'Night audit for 12 Oct did not finish. The business date did not change.'));
});

test('the alert says the audit waits for a blocker once the date has ended', function () {
    $setup = dashboardSetup(now: '2026-10-13 02:00');
    dashboardUnit($setup['property'], '2026-10-12', '2026-10-14', 'checkedIn');

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('alert.message', 'Night audit for 12 Oct is waiting: 1 item needs a fix before it can run.'));
});

test('the alert says the business date is more than a day behind', function () {
    $setup = dashboardSetup(date: '2026-10-10');

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('alert.message', 'The business date is 10 Oct. Run the night audit to catch up.'));

    $oneDay = dashboardSetup(date: '2026-10-11');

    $this->actingAs($oneDay['user'])->get(route('dashboard', $oneDay['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('alert', null));
});

test('quick actions follow their permissions', function () {
    $manage = dashboardSetup([PermissionEnum::ReservationsManage, PermissionEnum::NightAuditRun]);
    $view = dashboardSetup([PermissionEnum::ReservationsView]);
    $none = dashboardSetup([PermissionEnum::StaffView]);

    $this->actingAs($manage['user'])->get(route('dashboard', $manage['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('can', [
            'canManageReservations' => true,
            'canViewReservations' => false,
            'canRunNightAudit' => true,
            'canViewReports' => false,
            'canManageSettings' => false,
        ]));

    $this->actingAs($view['user'])->get(route('dashboard', $view['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('can.canViewReservations', true)->where('can.canManageReservations', false)->where('can.canRunNightAudit', false));

    $this->actingAs($none['user'])->get(route('dashboard', $none['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('can.canViewReservations', false)->where('tiles', null));
});

test('the tiles are deferred and a partial reload returns them', function () {
    $setup = dashboardSetup();

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('tiles')
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('tiles.date', '2026-10-12')
                ->has('tiles.arrivals')
                ->has('tiles.tonight')
                ->has('tiles.month')));
});

test('a tile the user may not see is not sent', function () {
    $reports = dashboardSetup([PermissionEnum::ReportsView]);
    $units = dashboardSetup([PermissionEnum::ReservationsView]);

    $this->actingAs($reports['user'])->get(route('dashboard', $reports['property']->code))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $page) => $page
            ->where('tiles.arrivals', null)->where('tiles.departures', null)->where('tiles.tonight', null)->has('tiles.month')));

    $this->actingAs($units['user'])->get(route('dashboard', $units['property']->code))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $page) => $page
            ->where('tiles.month', null)->has('tiles.arrivals')->has('tiles.departures')->has('tiles.tonight')));
});

test('arrivals and departures count what the reports count for the business date', function () {
    $setup = dashboardSetup();
    $property = $setup['property'];
    dashboardUnit($property, '2026-10-12', '2026-10-14');
    dashboardUnit($property, '2026-10-12', '2026-10-13');
    dashboardUnit($property, '2026-10-12', '2026-10-15', 'checkedIn');
    dashboardUnit($property, '2026-10-12', '2026-10-15', 'cancelled');
    dashboardUnit($property, '2026-10-13', '2026-10-14');
    dashboardUnit($property, '2026-10-10', '2026-10-12', 'checkedIn');
    dashboardUnit($property, '2026-10-10', '2026-10-12', 'checkedOut');
    dashboardUnit($property, '2026-10-10', '2026-10-12', 'cancelled');
    $other = Property::factory()->live()->create();
    dashboardUnit($other, '2026-10-12', '2026-10-13');

    $filters = new ReportFilters('2026-10-12', '2026-10-12');
    $arrivals = app(ArrivalsReport::class)->rows($property, $filters);
    $departures = app(DeparturesReport::class)->rows($property, $filters);

    $this->actingAs($setup['user'])->get(route('dashboard', $property->code))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $page) => $page
            ->where('tiles.arrivals.total', count($arrivals))
            ->where('tiles.arrivals.remaining', 2)
            ->where('tiles.departures.total', count($departures))
            ->where('tiles.departures.remaining', 1)));

    expect(count($arrivals))->toBe(3)->and(count($departures))->toBe(2);
});

test('posted equals the revenue report without tax and booked counts live units nights by stay date', function () {
    $setup = dashboardSetup();
    $property = $setup['property'];
    $room = TransactionCode::factory()->for($property)->create(['code' => 'ROOM']);
    $tax = TransactionCode::factory()->for($property)->create(['code' => 'SST', 'kind' => TransactionKind::Tax]);
    dashboardLine($property, $room, '100.00', '2026-09-30');
    dashboardLine($property, $room, '200.00', '2026-10-01');
    dashboardLine($property, $room, '50.50', '2026-10-12');
    dashboardLine($property, $room, '-20.00', '2026-10-12');
    dashboardLine($property, $tax, '12.00', '2026-10-12', TransactionKind::Tax);
    dashboardLine($property, $room, '-300.00', '2026-10-12', TransactionKind::Payment);
    dashboardLine($property, $room, '500.00', '2026-10-13');

    $rows = app(RevenueReport::class)->rows($property, new ReportFilters('2026-10-01', '2026-10-12'));
    $withoutTax = array_sum(array_map(fn ($row): float => $row->code === 'SST' ? 0 : (float) $row->amount, $rows));

    dashboardUnit($property, '2026-09-30', '2026-10-02');
    dashboardUnit($property, '2026-10-30', '2026-11-02', 'checkedIn');
    dashboardUnit($property, '2026-10-12', '2026-10-13', 'cancelled');

    $this->actingAs($setup['user'])->get(route('dashboard', $property->code))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $page) => $page
            ->where('tiles.month.month', 'October')
            ->where('tiles.month.from', '2026-10-01')
            ->where('tiles.month.to', '2026-10-12')
            ->where('tiles.month.posted', number_format($withoutTax, 2, '.', ''))
            ->where('tiles.month.bookedNights', 3)
            ->where('tiles.month.bookedAmount', '300.00')));

    expect($withoutTax)->toBe(230.5);
});

test('tonight reads all room types and the next seven nights', function () {
    $setup = dashboardSetup([PermissionEnum::ReservationsView]);
    $property = $setup['property'];
    $double = RoomType::factory()->for($property)->create(['name' => 'Double']);
    $dorm = RoomType::factory()->for($property)->bed()->create(['name' => 'Dorm']);
    Room::factory()->count(4)->for($property)->create(['room_type_id' => $double->id]);
    $dormRoom = Room::factory()->for($property)->create(['room_type_id' => $dorm->id]);
    Bed::factory()->count(2)->create(['room_id' => $dormRoom->id]);
    dashboardUnit($property, '2026-10-12', '2026-10-14', 'checkedIn', ['room_type_id' => $double->id]);
    dashboardUnit($property, '2026-10-13', '2026-10-14', 'confirmed', ['room_type_id' => $double->id]);

    $this->actingAs($setup['user'])->get(route('dashboard', $property->code))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $page) => $page
            ->where('tiles.tonight.occupancy', 17)
            ->where('tiles.tonight.roomsFree', 3)
            ->where('tiles.tonight.bedsFree', 2)
            ->has('tiles.tonight.nights', 7)
            ->where('tiles.tonight.nights.1.date', '2026-10-13')
            ->where('tiles.tonight.nights.1.occupancy', 33)
            ->where('tiles.tonight.roomTypes.0.name', 'Double')
            ->where('tiles.tonight.roomTypes.0.sold', 1)
            ->where('tiles.tonight.roomTypes.0.total', 4)
            ->where('tiles.tonight.roomTypes.0.left', 3)));
});

test('the tiles use the same number of queries for two and five room types', function () {
    $count = function (int $roomTypes): int {
        $setup = dashboardSetup();
        $property = $setup['property'];

        foreach (range(1, $roomTypes) as $index) {
            $type = RoomType::factory()->for($property)->create();
            Room::factory()->count(2)->for($property)->create(['room_type_id' => $type->id]);
            dashboardUnit($property, '2026-10-12', '2026-10-14', 'confirmed', ['room_type_id' => $type->id]);
        }

        $property->load('settings');
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(DashboardTiles::class)->forDate($property, CarbonImmutable::parse('2026-10-12'), true, true);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(5))->toBe($count(2));
});
