<?php

require_once __DIR__.'/../Support/GroupSetup.php';

use App\Actions\Staff\GrantPropertyAccess;
use App\Enums\DefaultRoleEnum;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\Group\GroupReservations;
use App\Services\Staff\GroupProperties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function groupReservationsUrl(Property $property, string $query = ''): string
{
    return route('group.reservations', $property->property_group_id).$query;
}

function groupBooking(Property $property, array $setup, string $key, string $guestName, string $arrival = '2026-10-20'): Reservation
{
    $guest = Guest::factory()->create(['property_id' => $property->id, 'full_name' => $guestName]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id, 'booker_guest_id' => $guest->id]);
    ReservationUnit::factory()->for($reservation)->withNights()->create(['room_type_id' => $setup['roomTypes'][$key]->id, 'arrival_date' => $arrival, 'departure_date' => date('Y-m-d', strtotime($arrival.' +2 days'))]);

    return $reservation;
}

test('the list shows every property\'s bookings from today, by arrival, with the property', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    groupBooking($setup['sepilok'], $setup, 'sepilok', 'Ann Lee', '2026-10-22');
    groupBooking($setup['kinabatangan'], $setup, 'kinabatangan', 'Ben Tan', '2026-10-18');
    groupBooking($setup['kinabatangan'], $setup, 'kinabatangan', 'Old Stay', '2026-09-01');

    $this->actingAs($setup['manager'])->get(groupReservationsUrl($setup['sepilok']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('group/reservations')
            ->where('filters.from', '2026-10-14')
            ->has('reservations.data', 2)
            ->where('reservations.data.0.guest', 'Ben Tan')
            ->where('reservations.data.0.propertyName', 'Tanini Kinabatangan')
            ->where('reservations.data.0.rooms', 1)
            ->where('reservations.data.1.guest', 'Ann Lee')
            ->has('properties', 2));
});

test('a search finds a guest at any property and on any date, and the property filter narrows it', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    groupBooking($setup['sepilok'], $setup, 'sepilok', 'Carla Wong', '2026-09-01');
    groupBooking($setup['kinabatangan'], $setup, 'kinabatangan', 'Carla Wong', '2026-11-05');
    groupBooking($setup['kinabatangan'], $setup, 'kinabatangan', 'Dan Lim');

    $this->actingAs($setup['manager'])->get(groupReservationsUrl($setup['sepilok'], '?search=carla'))
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', null)->has('reservations.data', 2));

    $this->get(groupReservationsUrl($setup['sepilok'], '?search=carla&property='.$setup['kinabatangan']->code))
        ->assertInertia(fn (Assert $page) => $page->has('reservations.data', 1)->where('reservations.data.0.propertyCode', $setup['kinabatangan']->code));
});

test('a property whose role does not show reservations is left out, and guest names follow the role', function () {
    $setup = liveGroupSetup();
    $noGuests = Role::query()->create(['property_id' => $setup['kinabatangan']->id, 'name' => 'Bookings only']);
    $noGuests->givePermissionTo('reservations.view');
    app(GrantPropertyAccess::class)($setup['kinabatanganOwner'], $setup['kinabatangan'], $setup['manager'], $noGuests);
    groupBooking($setup['kinabatangan'], $setup, 'kinabatangan', 'Hidden Name');

    $this->actingAs($setup['manager'])->get(groupReservationsUrl($setup['sepilok'], '?search=Hidden'))
        ->assertInertia(fn (Assert $page) => $page->has('reservations.data', 0));

    $this->get(groupReservationsUrl($setup['sepilok']))
        ->assertInertia(fn (Assert $page) => $page->has('reservations.data', 1)->where('reservations.data.0.guest', null));

    grantKinabatangan($setup, $setup['frontDesk'], DefaultRoleEnum::Housekeeping);
    $this->actingAs($setup['frontDesk'])->get(groupReservationsUrl($setup['sepilok']))
        ->assertInertia(fn (Assert $page) => $page->where('leftOut', ['Tanini Kinabatangan']));
});

test('the CSV has every matching booking with its property', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    groupBooking($setup['sepilok'], $setup, 'sepilok', 'Ann Lee');
    groupBooking($setup['kinabatangan'], $setup, 'kinabatangan', 'Ben Tan');

    $csv = $this->actingAs($setup['manager'])
        ->get(route('group.reservations.export', $setup['sepilok']->property_group_id))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toStartWith('Property,Reference,Guest')
        ->toContain('Ann Lee')
        ->toContain('Ben Tan');
});

test('a page of bookings costs the same queries however many bookings it shows', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $service = app(GroupReservations::class);
    [$properties, $guests] = $service->scope($setup['manager'], app(GroupProperties::class)->propertiesFor($setup['manager'], $setup['sepilok']->group));
    $filters = ['search' => null, 'status' => null, 'from' => '2026-10-14', 'to' => null, 'property' => null];
    $count = function () use ($service, $properties, $guests, $filters): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->page($properties, $guests, $filters);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    groupBooking($setup['sepilok'], $setup, 'sepilok', 'One');
    groupBooking($setup['kinabatangan'], $setup, 'kinabatangan', 'Two');
    Model::preventLazyLoading();

    try {
        $few = $count();
        groupBooking($setup['sepilok'], $setup, 'sepilok', 'Three');
        groupBooking($setup['kinabatangan'], $setup, 'kinabatangan', 'Four');
        $many = $count();
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($many)->toBe($few);
});

test('the side panel shows a booking of any property the role may view, with guests only for guests.view', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $booking = groupBooking($setup['kinabatangan'], $setup, 'kinabatangan', 'Ben Tan');
    $noGuests = Role::query()->create(['property_id' => $setup['kinabatangan']->id, 'name' => 'Bookings only']);
    $noGuests->givePermissionTo('reservations.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($setup['kinabatanganOwner'])
        ->post(route('settings.staff.access.store', $setup['kinabatangan']->code), ['user_id' => $setup['frontDesk']->id, 'role_id' => $noGuests->id])
        ->assertSessionHasNoErrors();
    $url = fn (Property $property, Reservation $reservation): string => route('group.peek', [$setup['sepilok']->property_group_id, $property->code, $reservation->id]);

    $this->actingAs($setup['manager'])->getJson($url($setup['kinabatangan'], $booking))
        ->assertOk()
        ->assertJsonPath('reference', $booking->reference)
        ->assertJsonPath('booker', 'Ben Tan')
        ->assertJsonPath('propertyName', 'Tanini Kinabatangan')
        ->assertJsonPath('units.0.party', fn (string $party) => str_starts_with($party, 'Ben Tan'))
        ->assertJsonPath('reservationUrl', route('reservations.show', [$setup['kinabatangan']->code, $booking->id], absolute: false));

    $this->getJson($url($setup['sepilok'], $booking))->assertNotFound();

    $this->actingAs($setup['frontDesk'])->getJson($url($setup['kinabatangan'], $booking))
        ->assertOk()
        ->assertJsonPath('booker', null)
        ->assertJsonPath('units.0.party', null)
        ->assertJsonPath('guestUrl', null);

    $housekeeper = User::factory()->for($setup['sepilok'])->withRole(DefaultRoleEnum::Manager)->create();
    grantKinabatangan($setup, $housekeeper, DefaultRoleEnum::Housekeeping);
    $this->actingAs($housekeeper)->getJson($url($setup['kinabatangan'], $booking))->assertNotFound();
});
