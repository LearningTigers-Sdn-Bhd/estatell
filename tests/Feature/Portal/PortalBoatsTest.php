<?php

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\ChargingUnit;
use App\Enums\CompanyStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitBoatTrip;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PortalBookingSetup.php';

/**
 * A portal company at a property with boats: a Boat in with a RM10 per guest fare, and a Boat out with no fare.
 *
 * @return array<string, mixed>
 */
function portalBoatSetup(): array
{
    Notification::fake();
    $setup = portalBookingSetup();
    Feature::for($setup['property'])->activate(Boats::class);
    TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::RoomRevenue)->create();
    $setup['fare'] = ChargeItem::factory()->for($setup['property'])->create(['name' => 'Jetty', 'default_amount' => '10.00', 'charging_unit' => ChargingUnit::PerGuest]);
    $setup['in'] = BoatDeparture::factory()->for($setup['property'])->create(['direction' => BoatDirection::In, 'leaves_at' => '10:00', 'arrives_at' => '10:30', 'charge_item_id' => $setup['fare']->id]);
    $setup['out'] = BoatDeparture::factory()->for($setup['property'])->create(['direction' => BoatDirection::Out, 'leaves_at' => '11:30', 'arrives_at' => '12:00', 'charge_item_id' => null]);

    return $setup;
}

test('the agent sets the boats when booking, for every room, and the fare shows on the bill', function () {
    $setup = portalBoatSetup();

    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.create', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->has('boatDepartures', 2));

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup, ['rooms' => 2])], [
        'expected_total' => '400.00',
        'boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id],
        'boat_out' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['out']->id],
    ]))->assertSessionHasNoErrors();

    $units = Reservation::query()->sole()->units()->get();

    expect($units)->toHaveCount(2)
        ->and($units->every(fn (ReservationUnit $unit): bool => substr($unit->arrival_time, 0, 5) === '10:30' && substr($unit->departure_time, 0, 5) === '11:30'))->toBeTrue()
        ->and(UnitBoatTrip::query()->count())->toBe(4)
        ->and(UnitAddOn::query()->where('charge_item_id', $setup['fare']->id)->sum('quantity'))->toEqual(4);
});

test('charter and own boat need a time, and a booking without one says so', function () {
    $setup = portalBoatSetup();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)], ['boat_in' => ['mode' => 'charter']]))
        ->assertSessionHasErrors();
    expect(Reservation::query()->count())->toBe(0);

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)], [
        'boat_in' => ['mode' => 'own', 'time' => '09:15', 'note' => 'Guest speedboat'],
        'boat_out' => ['mode' => 'charter', 'time' => '14:00'],
    ]))->assertSessionHasNoErrors();

    $unit = Reservation::query()->sole()->units()->sole();

    expect(substr($unit->arrival_time, 0, 5))->toBe('09:15')
        ->and(substr($unit->departure_time, 0, 5))->toBe('14:00')
        ->and(UnitBoatTrip::query()->where('direction', 'in')->sole()->mode)->toBe(BoatMode::Own);
});

test('boats are ignored at a property that does not use them', function () {
    $setup = portalBoatSetup();
    Feature::for($setup['property'])->deactivate(Boats::class);

    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.create', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->where('boatDepartures', []));

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)], ['boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id]]))
        ->assertSessionHasNoErrors();

    expect(UnitBoatTrip::query()->count())->toBe(0);
});

test('the agent sets each room\'s boats on the booking page, sees them, and Not set clears one', function () {
    $setup = portalBoatSetup();
    $reservation = portalMakeBooking($setup);
    $unit = $reservation->units()->sole();
    $url = route('portal.bookings.boats.update', ['property' => $setup['property'], 'reservation' => $reservation->id]);

    $this->actingAs($setup['portalUser'])->put($url, ['units' => [[
        'unit_id' => $unit->id,
        'in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id],
        'out' => ['mode' => 'charter', 'time' => '13:00'],
    ]]])->assertSessionHasNoErrors()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boats saved.']);

    $this->get(route('portal.bookings.show', ['property' => $setup['property'], 'reservation' => $reservation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('boatSetup.0.canSetBoatIn', true)->where('boatSetup.0.boatOut.mode', 'charter')->has('boatDepartures', 2));

    $this->put($url, ['units' => [['unit_id' => $unit->id, 'in' => null]]])->assertSessionHasNoErrors();

    expect(UnitBoatTrip::query()->where('direction', 'in')->count())->toBe(0)
        ->and(UnitBoatTrip::query()->where('direction', 'out')->count())->toBe(1);
});

test('Boat in closes at check-in and Boat out stays open until check-out', function () {
    $setup = portalBoatSetup();
    $reservation = portalMakeBooking($setup);
    $unit = $reservation->units()->sole();
    $unit->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();
    $url = route('portal.bookings.boats.update', ['property' => $setup['property'], 'reservation' => $reservation->id]);

    $this->actingAs($setup['portalUser'])->put($url, ['units' => [['unit_id' => $unit->id, 'in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id]]]])
        ->assertSessionHasErrors('form');
    $this->put($url, ['units' => [['unit_id' => $unit->id, 'out' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['out']->id]]]])
        ->assertSessionHasNoErrors();

    expect(UnitBoatTrip::query()->pluck('direction')->map(fn ($direction) => $direction->value)->all())->toBe(['out']);
});

test('another company gets a 404 and a suspended company is refused', function () {
    $setup = portalBoatSetup();
    $reservation = portalMakeBooking($setup);
    $unit = $reservation->units()->sole();
    $url = route('portal.bookings.boats.update', ['property' => $setup['property'], 'reservation' => $reservation->id]);
    $body = ['units' => [['unit_id' => $unit->id, 'out' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['out']->id]]]];
    $other = portalUser();
    portalLinkFor($other, $setup['property'], companyName: 'Other Agency');

    $this->actingAs($other)->put($url, $body)->assertNotFound();

    $setup['company']->update(['status' => CompanyStatus::Suspended]);
    $this->actingAs($setup['portalUser'])->put($url, $body)->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Your company is suspended. Contact the property.']);

    expect(UnitBoatTrip::query()->count())->toBe(0);
});
