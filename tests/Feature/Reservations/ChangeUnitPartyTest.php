<?php

use App\Actions\Reservations\ChangeUnitParty;
use App\Enums\ChargingUnit;
use App\Enums\PricingBasis;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Reservations\UnitPartyChangePreview;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/BookingSetup.php';

/**
 * A per-guest plan at 100.00 per adult a night (from `bookingSetup()`), a room type for 3 adults, and a confirmed unit
 * of 2 adults for 3 nights from 20 Oct priced 200.00 a night.
 *
 * @return array<string, mixed>
 */
function partySetup(string $source = 'phone'): array
{
    $setup = bookingSetup();
    $setup['plan']->update(['pricing_basis' => PricingBasis::PerGuest]);
    $setup['roomType']->update(['max_adults' => 3, 'max_children' => 2]);
    BusinessDate::factory()->create(['property_id' => $setup['property']->id, 'date' => '2026-10-14']);
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id, 'booking_source_id' => $setup[$source]->id, 'booker_guest_id' => $setup['guest']->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'adults' => 2,
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '200.00', 'extra_amount' => '0.00', 'supplement_amount' => '0.00', 'discount_amount' => '0.00', 'amount' => '200.00']);

    return [...$setup, 'reservation' => $reservation, 'unit' => $unit];
}

/**
 * @return list<string>
 */
function partyNightAmounts(ReservationUnit $unit): array
{
    return array_values($unit->nights()->orderBy('date')->pluck('amount')->all());
}

function partyUrl(array $setup, string $path = 'party'): string
{
    return route('reservations.units.'.str_replace('/', '.', $path), [$setup['property']->code, $setup['reservation'], $setup['unit']]);
}

test('more guests re-price every night not charged yet and the unit keeps the new party', function () {
    $setup = partySetup();

    // The property has no age groups, so the child of 9 pays the adult price: 4 × 100.00 a night.
    $result = app(ChangeUnitParty::class)($setup['user'], $setup['unit'], 3, [9]);

    $unit = $setup['unit']->refresh();
    expect($result->isRefused())->toBeFalse()
        ->and($result->repriced)->toBe(3)
        ->and($unit->adults)->toBe(3)
        ->and($unit->children)->toBe(1)
        ->and($unit->childAges()->pluck('age')->all())->toBe([9])
        ->and(partyNightAmounts($unit))->toBe(['400.00', '400.00', '400.00']);

    $log = Activity::query()->where('event', 'reservation.party_changed')->sole();
    expect($log->properties['previous_adults'])->toBe(2)
        ->and($log->properties['adults'])->toBe(3)
        ->and($log->properties['total'])->toBe('1200.00');
});

test('a night with a price set by hand keeps it and a charged night keeps it unless re-pricing is asked', function () {
    $setup = partySetup();
    [$first, $second] = $setup['unit']->nights()->orderBy('date')->get()->all();
    $second->update(['override_amount' => '150.00', 'amount' => '150.00']);
    $folio = Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'idempotency_key' => 'night:'.$first->id, 'amount' => '200.00']);

    app(ChangeUnitParty::class)($setup['user'], $setup['unit'], 1, []);

    expect(partyNightAmounts($setup['unit']))->toBe(['200.00', '150.00', '100.00']);
});

test('per-guest add-ons not charged yet follow the new party', function () {
    $setup = partySetup();
    $jetty = ChargeItem::factory()->for($setup['property'])->create(['name' => 'Jetty Fee', 'default_amount' => '10.00', 'charging_unit' => ChargingUnit::PerGuest]);
    (new UnitAddOn)->forceFill(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $jetty->id, 'quantity' => 2, 'unit_amount' => '10.00'])->save();

    app(ChangeUnitParty::class)($setup['user'], $setup['unit'], 3, []);

    $addOn = UnitAddOn::query()->where('charge_item_id', $jetty->id)->sole();
    expect($addOn->quantity)->toBe(3)->and($addOn->unit_amount)->toBe('10.00');
});

test('a channel booking keeps its OTA price and still changes its party and add-ons', function () {
    $setup = partySetup('ota');

    $result = app(ChangeUnitParty::class)($setup['user'], $setup['unit'], 3, []);

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->refresh()->adults)->toBe(3)
        ->and(partyNightAmounts($setup['unit']))->toBe(['200.00', '200.00', '200.00']);
});

test('refusals write and log nothing', function (array $change, string $refusal, bool $cancelled) {
    $setup = partySetup();

    if ($cancelled) {
        $setup['unit']->forceFill(['status' => UnitStatus::Cancelled])->save();
    }

    $result = app(ChangeUnitParty::class)($setup['user'], $setup['unit']->refresh(), ...$change);

    expect($result->refusal)->toBe($refusal)
        ->and($setup['unit']->refresh()->adults)->toBe(2)
        ->and(partyNightAmounts($setup['unit']))->toBe(['200.00', '200.00', '200.00'])
        ->and(Activity::query()->where('event', 'reservation.party_changed')->count())->toBe(0);
})->with([
    'too many adults' => [[4, []], UnitPartyChangePreview::REFUSAL_TOO_MANY_GUESTS, false],
    'too many children' => [[2, [3, 5, 7]], UnitPartyChangePreview::REFUSAL_TOO_MANY_GUESTS, false],
    'the same party' => [[2, []], UnitPartyChangePreview::REFUSAL_NOTHING_CHANGED, false],
    'a cancelled unit' => [[3, []], UnitPartyChangePreview::REFUSAL_UNIT_NOT_OPEN, true],
]);

test('the preview shows each night before and after, and the form saves the party', function () {
    $setup = partySetup();

    $this->actingAs($setup['user'])
        ->postJson(partyUrl($setup, 'party/preview'), ['adults' => 3, 'child_ages' => []])
        ->assertOk()
        ->assertJsonPath('nights.0.was', '200.00')
        ->assertJsonPath('nights.0.will', '300.00')
        ->assertJsonPath('before', '600.00')
        ->assertJsonPath('after', '900.00')
        ->assertJsonPath('difference', '300.00')
        ->assertJsonPath('error', null);

    $this->postJson(partyUrl($setup, 'party/preview'), ['adults' => 5, 'child_ages' => []])
        ->assertOk()
        ->assertJsonPath('error', 'This room type takes fewer guests.');

    $this->put(partyUrl($setup), ['adults' => 3, 'child_ages' => []])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Guests changed.']);

    expect($setup['unit']->refresh()->adults)->toBe(3);
});

test('changing guests needs reservations.manage and stays inside the property', function () {
    $setup = partySetup();
    $viewer = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();
    $other = User::factory()->owner()->create();

    $this->actingAs($viewer)->put(partyUrl($setup), ['adults' => 3, 'child_ages' => []])->assertForbidden();
    $this->actingAs($other)->put(partyUrl($setup), ['adults' => 3, 'child_ages' => []])->assertNotFound();

    expect($setup['unit']->refresh()->adults)->toBe(2);
});
