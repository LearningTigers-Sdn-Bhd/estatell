<?php

use App\Data\Reports\ArrivalRow;
use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Reports\ArrivalsReport;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A live property with the open business date 2026-10-10, and a user with the role.
 *
 * @return array{0: User, 1: Property}
 */
function arrivalsSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}

/**
 * @param  array<string, mixed>  $attributes  Unit attributes.
 */
function arrivingUnit(Property $property, string $arrival = '2026-10-10', array $attributes = [], array $reservation = []): ReservationUnit
{
    $booking = Reservation::factory()->create(['property_id' => $property->id, ...$reservation]);

    return ReservationUnit::factory()->for($booking)->create([
        'arrival_date' => $arrival,
        'departure_date' => date('Y-m-d', strtotime($arrival.' +2 days')),
        ...$attributes,
    ]);
}

function arrivalsUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/arrivals".$query;
}

test('a user without reports.view gets 403 and another property returns 404', function () {
    [$front, $property] = arrivalsSetup(DefaultRoleEnum::FrontDesk);

    $this->actingAs($front)->get(arrivalsUrl($property))->assertForbidden();

    [$manager, $own] = arrivalsSetup();
    [, $other] = arrivalsSetup();

    $this->actingAs($manager)->get(arrivalsUrl($own))->assertOk();
    $this->actingAs($manager)->get(arrivalsUrl($other))->assertNotFound();
});

test('the page opens on the business date and lists the units that arrive', function () {
    [$user, $property] = arrivalsSetup();
    arrivingUnit($property, '2026-10-10');
    arrivingUnit($property, '2026-10-11');

    $this->actingAs($user)->get(arrivalsUrl($property))->assertInertia(fn (Assert $page) => $page
        ->component('reports/arrivals')
        ->where('from', '2026-10-10')
        ->where('to', '2026-10-10')
        ->where('businessDate.date', '2026-10-10')
        ->has('businessDate.timeZone')
        ->where('maxRangeDays', 31)
        ->where('sort', 'arrival')
        ->has('layout.columns', 10)
        ->where('layout.orientation', 'portrait')
        ->where('canEditLayout', true)
        ->has('rows.data', 1)
        ->where('rows.meta.total', 1));
});

test('a single date in the URL is used for both ends', function () {
    [$user, $property] = arrivalsSetup();
    arrivingUnit($property, '2026-10-12');

    $this->actingAs($user)->get(arrivalsUrl($property, '?from=2026-10-12'))->assertInertia(fn (Assert $page) => $page
        ->where('to', '2026-10-12')
        ->has('rows.data', 1));
});

test('each row shows the guest, room, company and balance of the unit', function () {
    [$user, $property] = arrivalsSetup();
    $company = Company::factory()->for($property)->create(['name' => 'Borneo Travel']);
    $unit = arrivingUnit($property, '2026-10-10', ['adults' => 2, 'children' => 1], ['company_id' => $company->id]);
    $guest = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Tan Wei Ming', 'phone' => '+60123456789']);
    UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $guest->id]);
    $room = Room::factory()->for($property)->create(['room_type_id' => $unit->room_type_id, 'number' => '204']);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-10', 'room_id' => $room->id]);
    $folio = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $unit->reservation_id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '350.00', 'unit_amount' => '350.00']);
    FolioLine::factory()->payment('50.00')->create(['folio_id' => $folio->id]);
    $voided = Folio::factory()->voided()->create(['property_id' => $property->id, 'reservation_id' => $unit->reservation_id]);
    FolioLine::factory()->create(['folio_id' => $voided->id, 'amount' => '999.00', 'unit_amount' => '999.00']);

    $this->actingAs($user)->get(arrivalsUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.key', $unit->id)
        ->where('rows.data.0.reservation', $unit->reservation->reference)
        ->where('rows.data.0.guest', 'Tan Wei Ming')
        ->where('rows.data.0.phone', '+60123456789')
        ->where('rows.data.0.arrival', '2026-10-10')
        ->where('rows.data.0.nights', 2)
        ->where('rows.data.0.unit', "{$unit->roomType->name} / 204")
        ->where('rows.data.0.guests', '2 / 1')
        ->where('rows.data.0.company', 'Borneo Travel')
        ->where('rows.data.0.status.value', 'confirmed')
        ->where('rows.data.0.balance', '300.00'));
});

test('only the units of this property appear', function () {
    [$user, $property] = arrivalsSetup();
    [, $other] = arrivalsSetup();
    $own = arrivingUnit($property);
    arrivingUnit($other);

    $this->actingAs($user)->get(arrivalsUrl($property))->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)
        ->where('rows.data.0.key', $own->id));
});

test('cancelled and no-show units are left out and checked in units stay', function () {
    [$user, $property] = arrivalsSetup();
    arrivingUnit($property, '2026-10-10', ['status' => UnitStatus::Cancelled]);
    arrivingUnit($property, '2026-10-10', ['status' => UnitStatus::NoShow]);
    arrivingUnit($property, '2026-10-10', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);
    arrivingUnit($property, '2026-10-10', ['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()]);
    arrivingUnit($property, '2026-10-10');

    $this->actingAs($user)->get(arrivalsUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 3));
});

test('a range with the end before the start is rejected', function () {
    [$user, $property] = arrivalsSetup();

    $this->actingAs($user)->get(arrivalsUrl($property, '?from=2026-10-12&to=2026-10-10'))
        ->assertSessionHasErrors('to');
});

test('a range longer than 31 days is rejected and 31 days is allowed', function () {
    [$user, $property] = arrivalsSetup();

    $this->actingAs($user)->get(arrivalsUrl($property, '?from=2026-10-01&to=2026-11-01'))
        ->assertSessionHasErrors('to');
    $this->actingAs($user)->get(arrivalsUrl($property, '?from=2026-10-01&to=2026-10-31'))
        ->assertSessionDoesntHaveErrors();
});

test('a date in the wrong format is rejected', function () {
    [$user, $property] = arrivalsSetup();

    $this->actingAs($user)->get(arrivalsUrl($property, '?from=10-10-2026'))
        ->assertSessionHasErrors('from');
});

test('the report returns only the requested row keys', function () {
    [, $property] = arrivalsSetup();
    $first = arrivingUnit($property);
    $second = arrivingUnit($property);
    arrivingUnit($property);
    $filters = new ReportFilters('2026-10-10', '2026-10-10');
    $report = app(ArrivalsReport::class);

    expect($report->rows($property, $filters))->toHaveCount(3)
        ->and(array_map(fn (ArrivalRow $row): int => $row->key, $report->rows($property, $filters, [$first->id, $second->id])))->toBe([$first->id, $second->id])
        ->and($report->rows($property, $filters, []))->toBe([]);
});

test('a row key outside the property or the dates is ignored', function () {
    [, $property] = arrivalsSetup();
    [, $other] = arrivalsSetup();
    $foreign = arrivingUnit($other);
    $later = arrivingUnit($property, '2026-10-20');

    expect(app(ArrivalsReport::class)->rows($property, new ReportFilters('2026-10-10', '2026-10-10'), [$foreign->id, $later->id]))->toBe([]);
});

test('rows are sorted and paged on the server', function () {
    [$user, $property] = arrivalsSetup();

    foreach (range(1, 26) as $number) {
        $unit = arrivingUnit($property);
        $guest = Guest::factory()->create(['property_id' => $property->id, 'full_name' => sprintf('Guest %02d', $number)]);
        UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $guest->id]);
    }

    $this->actingAs($user)->get(arrivalsUrl($property, '?sort=-guest'))->assertInertia(fn (Assert $page) => $page
        ->where('sort', '-guest')
        ->has('rows.data', 25)
        ->where('rows.meta.total', 26)
        ->where('rows.meta.last_page', 2)
        ->where('rows.data.0.guest', 'Guest 26'));

    $this->actingAs($user)->get(arrivalsUrl($property, '?sort=guest&page=2'))->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)
        ->where('rows.meta.current_page', 2)
        ->where('rows.data.0.guest', 'Guest 26'));
});

test('an unknown sort falls back to the arrival date', function () {
    [$user, $property] = arrivalsSetup();
    arrivingUnit($property, '2026-10-12');
    arrivingUnit($property, '2026-10-11');

    $this->actingAs($user)->get(arrivalsUrl($property, '?from=2026-10-10&to=2026-10-12&sort=bogus'))->assertInertia(fn (Assert $page) => $page
        ->where('sort', 'arrival')
        ->where('rows.data.0.arrival', '2026-10-11')
        ->where('rows.data.1.arrival', '2026-10-12'));
});

test('the arrivals report is in the registry with its columns', function () {
    $keys = array_map(fn ($column): string => $column->key, ReportKey::Arrivals->columns());
    $visible = array_map(fn ($column): string => $column->key, array_filter(ReportKey::Arrivals->columns(), fn ($column): bool => $column->defaultVisible));

    expect($keys)->toBe(['reservation', 'guest', 'phone', 'arrival', 'nights', 'unit', 'guests', 'company', 'status', 'balance'])
        ->and(array_values($visible))->toBe(['reservation', 'guest', 'arrival', 'nights', 'unit', 'status', 'balance'])
        ->and(ReportKey::Arrivals->maxRangeDays())->toBe(31)
        ->and(ReportKey::Arrivals->report())->toBeInstanceOf(ArrivalsReport::class);
});

test('the default roles that get reports.view', function () {
    expect(DefaultRoleEnum::Owner->permissions())->toContain(PermissionEnum::ReportsView)
        ->and(DefaultRoleEnum::Manager->permissions())->toContain(PermissionEnum::ReportsView)
        ->and(DefaultRoleEnum::Finance->permissions())->toContain(PermissionEnum::ReportsView)
        ->and(DefaultRoleEnum::FrontDesk->permissions())->not->toContain(PermissionEnum::ReportsView)
        ->and(DefaultRoleEnum::Housekeeping->permissions())->not->toContain(PermissionEnum::ReportsView);
});

test('the Reports navigation groups the reports by category and needs reports.view', function () {
    [$manager, $property] = arrivalsSetup();
    [$front, $frontProperty] = arrivalsSetup(DefaultRoleEnum::FrontDesk);

    $this->actingAs($manager)->get(arrivalsUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('navigation.active', 'reports')
        ->where('navigation.contexts', fn ($contexts) => collect($contexts)->firstWhere('key', 'reports') === [
            'key' => 'reports',
            'label' => 'Reports',
            'icon' => 'chart-column',
            'href' => "/p/{$property->code}/reports/arrivals",
            'groups' => [
                [
                    'label' => 'Front desk',
                    'items' => [
                        ['title' => 'Arrivals', 'href' => "/p/{$property->code}/reports/arrivals", 'icon' => 'calendar-check', 'external' => false, 'active' => true, 'action' => null],
                        ['title' => 'Departures', 'href' => "/p/{$property->code}/reports/departures", 'icon' => 'door-open', 'external' => false, 'active' => false, 'action' => null],
                        ['title' => 'In-house', 'href' => "/p/{$property->code}/reports/in-house", 'icon' => 'users', 'external' => false, 'active' => false, 'action' => null],
                    ],
                ],
                [
                    'label' => 'Revenue',
                    'items' => [
                        ['title' => 'Occupancy', 'href' => "/p/{$property->code}/reports/occupancy", 'icon' => 'percent', 'external' => false, 'active' => false, 'action' => null],
                        ['title' => 'Revenue', 'href' => "/p/{$property->code}/reports/revenue", 'icon' => 'chart-column', 'external' => false, 'active' => false, 'action' => null],
                        ['title' => 'Tax', 'href' => "/p/{$property->code}/reports/tax", 'icon' => 'landmark', 'external' => false, 'active' => false, 'action' => null],
                    ],
                ],
                [
                    'label' => 'Finance',
                    'items' => [
                        ['title' => 'Payments', 'href' => "/p/{$property->code}/reports/payments", 'icon' => 'credit-card', 'external' => false, 'active' => false, 'action' => null],
                        ['title' => 'Folio ledger', 'href' => "/p/{$property->code}/reports/folio-ledger", 'icon' => 'receipt', 'external' => false, 'active' => false, 'action' => null],
                    ],
                ],
                [
                    'label' => 'Kitchen',
                    'items' => [
                        ['title' => 'Meal counts', 'href' => "/p/{$property->code}/reports/meal-counts", 'icon' => 'utensils', 'external' => false, 'active' => false, 'action' => null],
                    ],
                ],
            ],
        ]));

    $this->actingAs($front)->get(route('dashboard', $frontProperty->code))->assertInertia(fn (Assert $page) => $page
        ->where('navigation.contexts', fn ($contexts) => collect($contexts)->doesntContain('key', 'reports')));
});
