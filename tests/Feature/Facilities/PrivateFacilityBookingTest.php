<?php

use App\Actions\Facilities\BookFacility;
use App\Actions\Facilities\FacilityBookingResult;
use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\FolioType;
use App\Models\Facility;
use App\Models\Folio;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Facilities\FacilityBookingCharge;

require_once __DIR__.'/../Support/EventSetup.php';

/**
 * The event setup (Facilities on), a reservation with one unit arriving 14 Oct 2026, its Master folio, and a River
 * Safari of 12 places at 120.00 per person, or `$privateAmount` for a private boat.
 *
 * @return array{property: Property, owner: User, reservation: Reservation, master: Folio, safari: Facility}
 */
function privateSafariSetup(?string $privateAmount = '700.00'): array
{
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    ReservationUnit::factory()->for($reservation)->create(['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
    $master = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id, 'type' => FolioType::Master]);
    $safari = Facility::factory()->byPerson()->create(['property_id' => $property->id, 'name' => 'River Safari', 'capacity' => 12, 'private_amount' => $privateAmount]);
    $safari->chargeItem->update(['default_amount' => '120.00']);

    return compact('property', 'owner', 'reservation', 'master', 'safari');
}

/**
 * A booking of the safari on 15 Oct 2026, 16:00–18:00, on the Master folio.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 */
function safariInput(array $setup, array $overrides = []): FacilityBookingInputData
{
    return new FacilityBookingInputData(...[
        'facilityId' => $setup['safari']->id,
        'bookingDate' => '2026-10-15',
        'startsAt' => '16:00',
        'endsAt' => '18:00',
        'quantity' => 1,
        'unitAmount' => null,
        'notes' => null,
        'chargeTo' => 'f'.$setup['master']->id,
        ...$overrides,
    ]);
}

test('a private booking takes the whole facility at its time at the private price, charged once', function () {
    $setup = privateSafariSetup();

    $result = app(BookFacility::class)->forReservation($setup['owner'], $setup['reservation'], safariInput($setup, ['quantity' => 6, 'private' => true]));
    $booking = $result->booking->load(FacilityBookingCharge::relations());
    $charge = app(FacilityBookingCharge::class)->for($setup['property']->load('settings'), $booking);

    expect($result->isRefused())->toBeFalse()
        ->and($booking->private)->toBeTrue()
        ->and($booking->unit_amount)->toBe('700.00')
        ->and($charge['quantity'])->toBe(1)
        ->and((string) $charge['unitAmount']->getAmount())->toBe('700.00');

    $shared = app(BookFacility::class)->forReservation($setup['owner'], $setup['reservation'], safariInput($setup, ['quantity' => 1]));

    expect($shared->refusal)->toBe(FacilityBookingResult::REFUSAL_FULL);
});

test('a private booking is refused while others are booked at that time, or when the facility has no private price', function (?string $privateAmount, bool $sharedFirst, string $refusal) {
    $setup = privateSafariSetup($privateAmount);

    if ($sharedFirst) {
        app(BookFacility::class)->forReservation($setup['owner'], $setup['reservation'], safariInput($setup, ['quantity' => 2]));
    }

    $result = app(BookFacility::class)->forReservation($setup['owner'], $setup['reservation'], safariInput($setup, ['quantity' => 4, 'private' => true]));

    expect($result->refusal)->toBe($refusal);
})->with([
    'two guests already on the boat' => ['700.00', true, FacilityBookingResult::REFUSAL_FULL],
    'no private price' => [null, false, FacilityBookingResult::REFUSAL_PRIVATE_NOT_OFFERED],
]);

test('the quote prices a private booking once, with its breakdown', function () {
    $setup = privateSafariSetup();

    $this->actingAs($setup['owner'])
        ->postJson(route('facility-bookings.quote', ['property' => $setup['property']]), ['reservation_id' => $setup['reservation']->id, 'facility_id' => $setup['safari']->id, 'booking_date' => '2026-10-15', 'starts_at' => '16:00', 'ends_at' => '18:00', 'quantity' => 6, 'private' => true, 'charge_to' => 'f'.$setup['master']->id])
        ->assertOk()
        ->assertJsonPath('amount', '700.00')
        ->assertJsonPath('breakdown', 'Private · 6 people');
});

test('settings save a private price for a person facility only', function () {
    ['owner' => $owner, 'property' => $property] = eventSetup();

    $this->actingAs($owner)->post(route('settings.facilities.store', $property->code), [
        'name' => 'River Safari', 'booked_by' => 'person', 'capacity' => '12', 'charge_by_hand' => false, 'default_amount' => '120', 'rate_basis' => 'each', 'private_amount' => '700',
    ])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(route('settings.facilities.store', $property->code), [
        'name' => 'Kayak', 'booked_by' => 'item', 'capacity' => '10', 'charge_by_hand' => false, 'default_amount' => '40', 'rate_basis' => 'hour', 'private_amount' => '700',
    ])->assertSessionHasNoErrors();

    expect(Facility::query()->where('name', 'River Safari')->value('private_amount'))->toBe('700.00')
        ->and(Facility::query()->where('name', 'Kayak')->value('private_amount'))->toBeNull();
});
