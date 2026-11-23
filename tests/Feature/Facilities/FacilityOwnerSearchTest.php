<?php

use App\Enums\EventStatus;
use App\Enums\FolioType;
use App\Enums\PermissionEnum;
use App\Features\Facilities;
use App\Models\Event;
use App\Models\Facility;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/EventSetup.php';
require_once __DIR__.'/../StayView/Support/StayViewHelpers.php';

/**
 * A live reservation ES-…, booked by Lee Wei, with one Confirmed unit in room 101 (14–17 Oct 2026).
 */
function ownerSearchReservation(array $setup, string $booker = 'Lee Wei', string $room = '101', ?string $state = null): Reservation
{
    $reservation = Reservation::factory()->create([
        'property_id' => $setup['property']->id,
        'booker_guest_id' => Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => $booker])->id,
    ]);
    stayViewUnit($setup['property'], Room::factory()->for($setup['property'])->create(['number' => $room]), reservation: $reservation, state: $state);

    return $reservation;
}

test('the reservation search finds live reservations by booker, reference or room', function () {
    $setup = eventSetup();
    $lee = ownerSearchReservation($setup);
    $tan = ownerSearchReservation($setup, 'Tan Mei', '205');
    ownerSearchReservation($setup, 'Leeson Ong', '302', state: 'cancelled');
    $search = fn (string $q) => $this->actingAs($setup['owner'])
        ->getJson(route('facility-bookings.owners.reservations', [$setup['property']->code, 'q' => $q]))
        ->assertOk();

    $search('lee')->assertJsonCount(1)
        ->assertJsonPath('0.id', $lee->id)
        ->assertJsonPath('0.label', 'Lee Wei · '.$lee->reference)
        ->assertJsonPath('0.detail', fn (string $detail): bool => str_contains($detail, '101') && str_ends_with($detail, '14–17 Oct'));
    $search('205')->assertJsonCount(1)->assertJsonPath('0.id', $tan->id);
    $search(mb_strtolower($tan->reference))->assertJsonPath('0.id', $tan->id);
    $search('l')->assertExactJson([]);
});

test('the reservation search stays inside the property and needs reservations.view and facilities.book', function () {
    $setup = eventSetup();
    $other = eventSetup();
    ownerSearchReservation($other);

    $this->actingAs($setup['owner'])
        ->getJson(route('facility-bookings.owners.reservations', [$setup['property']->code, 'q' => 'lee']))
        ->assertExactJson([]);

    foreach ([[PermissionEnum::FacilitiesBook], [PermissionEnum::ReservationsView]] as $permissions) {
        $clerk = User::factory()->for($setup['property'])->withPermissions($permissions)->create();

        $this->actingAs($clerk)
            ->getJson(route('facility-bookings.owners.reservations', [$setup['property']->code, 'q' => 'lee']))
            ->assertForbidden();
    }
});

test('a picked reservation sends what the Book facility Sheet needs', function () {
    $setup = eventSetup();
    $reservation = ownerSearchReservation($setup);
    $master = Folio::factory()->create(['property_id' => $setup['property']->id, 'reservation_id' => $reservation->id, 'type' => FolioType::Master]);
    $hall = Facility::factory()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['owner'])
        ->getJson(route('facility-bookings.owners.reservation', [$setup['property']->code, $reservation->id]))
        ->assertOk()
        ->assertJsonPath('facilities.0.id', $hall->id)
        ->assertJsonPath('chargeToOptions.0.value', 'f'.$master->id)
        ->assertJsonPath('defaultChargeTo.'.$hall->id, 'u'.$reservation->units()->value('id'))
        ->assertJsonPath('facilityDefaultDate', '2026-10-14');

    $this->actingAs($setup['owner'])
        ->getJson(route('facility-bookings.owners.reservation', [$setup['property']->code, ownerSearchReservation(eventSetup())->id]))
        ->assertNotFound();
});

test('the event list has the Tentative and Confirmed events on the date, and the facilities', function () {
    $setup = eventSetup();
    $property = $setup['property'];
    $wedding = Event::factory()->create(['property_id' => $property->id, 'name' => 'Lim & Tan', 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-21']);
    $tentative = Event::factory()->create(['property_id' => $property->id, 'name' => 'Sabah Tourism', 'status' => EventStatus::Tentative]);
    Event::factory()->create(['property_id' => $property->id, 'status' => EventStatus::Cancelled]);
    Event::factory()->create(['property_id' => $property->id, 'status' => EventStatus::Completed]);
    Event::factory()->create(['property_id' => $property->id, 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-21']);
    $hall = Facility::factory()->create(['property_id' => $property->id]);

    $this->actingAs($setup['owner'])
        ->getJson(route('facility-bookings.owners.events', [$property->code, 'date' => '2026-10-20']))
        ->assertOk()
        ->assertJsonCount(2, 'events')
        ->assertJsonPath('events.0.id', $wedding->id)
        ->assertJsonPath('events.0.endsOn', '2026-10-21')
        ->assertJsonPath('events.1.id', $tentative->id)
        ->assertJsonPath('facilities.0.id', $hall->id);

    $this->actingAs($setup['owner'])
        ->getJson(route('facility-bookings.owners.events', [$property->code, 'date' => '20/10/2026']))
        ->assertUnprocessable();
});

test('the owner routes are not found with the feature off', function () {
    $setup = eventSetup();
    Feature::for($setup['property'])->deactivate(Facilities::class);

    $this->actingAs($setup['owner'])
        ->getJson(route('facility-bookings.owners.events', [$setup['property']->code, 'date' => '2026-10-20']))
        ->assertNotFound();
});

test('New event starts on the date with a line for the facility from the Timeline, and ignores bad values', function () {
    $setup = eventSetup();
    $hall = Facility::factory()->create(['property_id' => $setup['property']->id]);
    $foreign = Facility::factory()->create();

    $this->actingAs($setup['owner'])
        ->get(route('events.create', [$setup['property']->code, 'date' => '2026-10-20', 'facility' => $hall->id]))
        ->assertInertia(fn (Assert $page) => $page->where('defaultDate', '2026-10-20')->where('defaultFacilityId', $hall->id));

    $this->actingAs($setup['owner'])
        ->get(route('events.create', [$setup['property']->code, 'date' => '2026-02-30', 'facility' => $foreign->id]))
        ->assertInertia(fn (Assert $page) => $page->where('defaultDate', '2026-10-14')->where('defaultFacilityId', null));
});

test('the Timeline offers Book facility to staff with facilities.book', function () {
    $setup = eventSetup();
    $clerk = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::FrontDeskView])->create();

    $this->actingAs($setup['owner'])->get(route('front-desk.timeline', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('canBookFacility', true)->where('canManageEvents', true));
    $this->actingAs($clerk)->get(route('front-desk.timeline', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('canBookFacility', false));
});
