<?php

use App\Actions\Registration\SeedRegistrationCardFields;
use App\Actions\Registration\SeedRegistrationTerms;
use App\Enums\KioskKeyMode;
use App\Enums\PaymentPurpose;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tax;
use App\Models\UnitNight;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/KioskSetup.php';

/**
 * A kiosk stay of two nights in room 101, card not required, nothing due.
 *
 * @param  array<string, mixed>  $settings
 * @param  array<string, mixed>  $guest
 * @return array<string, mixed>
 */
function kioskCheckInSetup(array $settings = [], bool $room = true, array $guest = []): array
{
    $setup = kioskStaySetup(guest: $guest);
    $setup['property']->settings->forceFill(['card_required_for_check_in' => false, 'security_deposit_amount' => '0.00', ...$settings])->save();

    if ($room) {
        $room = Room::factory()->create(['property_id' => $setup['property']->id, 'room_type_id' => $setup['unit']->room_type_id, 'number' => '101']);

        foreach (['2026-10-14', '2026-10-15'] as $date) {
            UnitNight::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'date' => $date, 'room_id' => $room->id]);
        }
    }

    return $setup;
}

function kioskPayDeposit(array $setup, string $amount = '100.00'): void
{
    $folio = Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['unit']->reservation_id]);
    Payment::factory()->create(['folio_id' => $folio->id, 'amount' => $amount, 'purpose' => PaymentPurpose::Advance]);
}

it('says a stay with nothing due is eligible', function () {
    ['unit' => $unit] = kioskCheckInSetup();

    kioskRead("/stays/{$unit->id}/eligibility")->assertOk()->assertExactJson(['eligible' => true]);
});

it('says deposit_not_paid until the deposit is on the unit folio, then eligible', function () {
    $setup = kioskCheckInSetup(['security_deposit_amount' => '100.00']);

    kioskRead("/stays/{$setup['unit']->id}/eligibility")->assertOk()->assertExactJson(['eligible' => false, 'reason' => 'deposit_not_paid']);

    kioskPayDeposit($setup, '60.00');
    kioskRead("/stays/{$setup['unit']->id}/eligibility")->assertJsonPath('reason', 'deposit_not_paid');

    kioskPayDeposit($setup, '40.00');
    kioskRead("/stays/{$setup['unit']->id}/eligibility")->assertExactJson(['eligible' => true]);
});

it('checks in with the front desk action and gives the room and key mode, logging only the kiosk id', function () {
    ['unit' => $unit, 'kiosk' => $kiosk, 'property' => $property] = kioskCheckInSetup();
    $property->settings->forceFill(['kiosk_key_mode' => KioskKeyMode::Both])->save();

    kioskPost("/stays/{$unit->id}/check-in")->assertOk()->assertExactJson(['room' => ['number' => '101'], 'key_mode' => 'both']);

    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->checked_in_at)->not->toBeNull();

    $event = Activity::query()->where('event', 'reservation.unit_checked_in')->sole();
    $marker = Activity::query()->where('event', 'kiosk.checked_in')->sole();
    expect($event->causer_id)->toBeNull()
        ->and($marker->properties->all())->toBe(['property_id' => $property->id, 'kiosk_id' => $kiosk->id]);
});

it('answers the same room again for a stay the kiosk already checked in, also with a new key', function () {
    ['unit' => $unit] = kioskCheckInSetup();

    kioskPost("/stays/{$unit->id}/check-in", headers: ['Idempotency-Key' => 'k-1'])->assertOk();
    kioskPost("/stays/{$unit->id}/check-in", headers: ['Idempotency-Key' => 'k-2'])->assertOk()->assertJsonPath('room.number', '101');

    expect(Activity::query()->where('event', 'reservation.unit_checked_in')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'kiosk.checked_in')->count())->toBe(1);
});

it('does not show the room after the kiosk key window expires', function () {
    ['unit' => $unit] = kioskCheckInSetup();
    kioskPost("/stays/{$unit->id}/check-in")->assertOk();

    $this->travelTo(now()->addMinutes(31));

    kioskPost("/stays/{$unit->id}/check-in")
        ->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    expect(Activity::query()->where('event', 'reservation.unit_checked_in')->count())->toBe(1);
});

it('replays the first answer for the same Idempotency-Key', function () {
    ['unit' => $unit] = kioskCheckInSetup();
    $headers = ['Idempotency-Key' => 'b1f0c9de-7a0e-4a52-9d57-1a2f9d3c3001'];

    kioskPost("/stays/{$unit->id}/check-in", headers: $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    kioskPost("/stays/{$unit->id}/check-in", headers: $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('room.number', '101');

    expect(Activity::query()->where('event', 'reservation.unit_checked_in')->count())->toBe(1);
});

it('does not show the room of a stay the front desk checked in', function () {
    ['unit' => $unit] = kioskCheckInSetup();
    $unit->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();

    kioskPost("/stays/{$unit->id}/check-in")->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
});

it('refuses with deposit_not_paid and writes nothing', function () {
    ['unit' => $unit] = kioskCheckInSetup(['security_deposit_amount' => '100.00']);

    kioskPost("/stays/{$unit->id}/check-in")->assertStatus(409)->assertJsonPath('error.code', 'deposit_not_paid');

    expect($unit->refresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(Activity::query()->where('event', 'kiosk.checked_in')->exists())->toBeFalse();
});

it('refuses with card_required_for_check_in until the card is signed', function () {
    $setup = kioskCheckInSetup(['card_required_for_check_in' => true]);
    app(SeedRegistrationCardFields::class)($setup['property']);
    app(SeedRegistrationTerms::class)($setup['property'], PropertyType::Hotel);

    kioskPost("/stays/{$setup['unit']->id}/check-in")->assertStatus(409)->assertJsonPath('error.code', 'card_required_for_check_in');
    expect($setup['unit']->refresh()->status)->toBe(UnitStatus::Confirmed);
});

it('refuses with nationality_required when a foreign-only tax is on and the guest has none', function () {
    $setup = kioskCheckInSetup();
    Tax::factory()->create(['property_id' => $setup['property']->id, 'enabled' => true, 'foreign_guests_only' => true]);

    kioskPost("/stays/{$setup['unit']->id}/check-in")->assertStatus(409)->assertJsonPath('error.code', 'nationality_required');

    $setup['guest']->forceFill(['nationality_code' => 'SG'])->save();
    kioskPost("/stays/{$setup['unit']->id}/check-in")->assertOk();
});

it('answers server_error when no room is ready for tonight', function () {
    ['unit' => $unit] = kioskCheckInSetup(room: false);

    kioskPost("/stays/{$unit->id}/check-in")->assertStatus(409)->assertJsonPath('error.code', 'server_error');
    expect($unit->refresh()->status)->toBe(UnitStatus::Confirmed);
});

it('answers booking_not_found for another property, a missing stay and a stay outside the window, and counts them as failures', function () {
    ['unit' => $unit, 'property' => $property] = kioskCheckInSetup();
    $other = kioskStayFor(Property::factory()->create(), 'R-900');
    $early = kioskStayFor($property, 'R-901', ['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22']);

    foreach ([$other['unit']->id, 999999, $early['unit']->id, 'abc'] as $id) {
        kioskRead("/stays/{$id}/eligibility")->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
        kioskPost("/stays/{$id}/check-in")->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    }

    expect($other['unit']->refresh()->status)->toBe(UnitStatus::Confirmed);
});

it('locks a token out of every by-id call after ten misses, and a found stay does not use up attempts', function () {
    ['unit' => $unit] = kioskCheckInSetup();

    foreach (range(1, 20) as $ignored) {
        kioskRead("/stays/{$unit->id}/eligibility")->assertOk();
    }

    foreach (range(1, 10) as $miss) {
        kioskRead('/stays/999999/eligibility')->assertNotFound();
    }

    kioskRead('/stays/999999/eligibility')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
    kioskRead("/stays/{$unit->id}/eligibility")->assertStatus(429);
    kioskPost("/stays/{$unit->id}/check-in")->assertStatus(429);
    kioskPost("/stays/{$unit->id}/identity", ['kind' => 'my_kad', 'number' => '900101141234', 'full_name' => 'Aisha', 'nationality' => 'MY'])->assertStatus(429);
});

it('refuses a missing or wrong-scope token on every route of this slice', function () {
    ['unit' => $unit, 'kiosk' => $kiosk] = kioskCheckInSetup();

    kioskRead("/stays/{$unit->id}/eligibility", token: null)->assertUnauthorized();
    kioskRead('/config', token: null)->assertUnauthorized();
    kioskPost("/stays/{$unit->id}/check-in", token: null)->assertUnauthorized();

    $kiosk->forceFill(['scopes' => 'v1:read'])->save();
    kioskRead('/config')->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    kioskPost("/stays/{$unit->id}/check-in")->assertUnauthorized();
});

it('gives the config of the token property', function () {
    ['property' => $property] = kioskCheckInSetup();
    $property->settings->forceFill(['kiosk_key_mode' => KioskKeyMode::Card, 'kiosk_walk_in_enabled' => true, 'kiosk_idle_timeout_seconds' => 90])->save();

    kioskRead('/config')->assertOk()->assertExactJson([
        'property_name' => 'Palm Resort',
        'languages' => ['en', 'ms'],
        'key_mode' => 'card',
        'walk_in_enabled' => true,
        'idle_timeout_seconds' => 90,
        'currency' => $property->settings->currency->getCurrencyCode(),
    ]);
});

it('defaults the config to PIN, no walk-in and 60 seconds', function () {
    kioskStaySetup();

    kioskRead('/config')->assertOk()->assertJsonPath('key_mode', 'pin')->assertJsonPath('walk_in_enabled', false)->assertJsonPath('idle_timeout_seconds', 60);
});
