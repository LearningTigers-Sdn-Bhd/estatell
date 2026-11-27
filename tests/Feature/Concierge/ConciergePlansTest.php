<?php

use App\Enums\FacilityBookedBy;
use App\Enums\FacilityBookingStatus;
use App\Enums\TransferMode;
use App\Features\Facilities;
use App\Features\Transfers;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Reservation;
use App\Models\TransferRoute;
use App\Models\UnitTransfer;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ConciergeSetup.php';

/**
 * The in-stay guest (12 to 14 Oct, today 14 Oct) with a transfer out at 11:00 and four River Safari bookings: one today
 * for 2 people, a private one, one yesterday and one cancelled. Another booking's safari is on the same day.
 *
 * @return array<string, mixed>
 */
function conciergePlansSetup(): array
{
    $setup = conciergeInStaySetup();
    $property = $setup['property'];
    Feature::for($property)->activate([Transfers::class, Facilities::class]);
    $route = TransferRoute::factory()->for($property)->out()->create(['place' => 'Sandakan Airport']);
    UnitTransfer::factory()->create(['property_id' => $property->id, 'reservation_unit_id' => $setup['unit']->id, 'direction' => 'out', 'transfer_route_id' => $route->id, 'pickup_time' => '11:00', 'flight_number' => 'AK 6232', 'mode' => TransferMode::Shared]);
    $safari = Facility::factory()->create(['property_id' => $property->id, 'name' => 'River Safari', 'booked_by' => FacilityBookedBy::Person]);
    $book = fn (array $attributes) => FacilityBooking::factory()->create([
        'event_id' => null,
        'reservation_id' => $setup['unit']->reservation_id,
        'property_id' => $property->id,
        'facility_id' => $safari->id,
        'folio_id' => $setup['folio']->id,
        'booking_date' => '2026-10-14',
        'starts_at' => '06:00:00',
        'ends_at' => '08:00:00',
        'quantity' => 2,
        'status' => FacilityBookingStatus::Confirmed,
        ...$attributes,
    ]);
    $book([]);
    $book(['starts_at' => '16:00:00', 'ends_at' => '18:00:00', 'private' => true]);
    $book(['booking_date' => '2026-10-13']);
    $book(['status' => FacilityBookingStatus::Cancelled, 'starts_at' => '20:00:00', 'ends_at' => '22:00:00']);
    $book(['reservation_id' => Reservation::factory()->create(['property_id' => $property->id])->id, 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']);

    return $setup;
}

it('shows the guest their road transfers and today\'s activities by time, without anyone else\'s', function () {
    ['token' => $token] = conciergePlansSetup();

    $this->get("/c/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stay.step', 'home')
        ->where('stay.plans', [
            ['kind' => 'activity', 'date' => '2026-10-14', 'time' => '06:00–08:00', 'title' => 'River Safari', 'detail' => '2 people'],
            ['kind' => 'transfer', 'date' => '2026-10-14', 'time' => '11:00', 'title' => 'Transfer to Sandakan Airport', 'detail' => 'Flight AK 6232 · Shared van'],
            ['kind' => 'activity', 'date' => '2026-10-14', 'time' => '16:00–18:00', 'title' => 'River Safari', 'detail' => 'Private'],
        ]));
});

it('shows no plans while the features are off', function () {
    ['token' => $token, 'property' => $property] = conciergePlansSetup();
    Feature::for($property)->deactivate([Transfers::class, Facilities::class]);

    $this->get("/c/{$token}")->assertInertia(fn (Assert $page) => $page->where('stay.plans', []));
});
