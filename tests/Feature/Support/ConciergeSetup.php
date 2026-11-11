<?php

use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Actions\Registration\SeedRegistrationCardFields;
use App\Actions\Registration\SeedRegistrationTerms;
use App\Enums\Locale;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Features\Concierge;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitNight;
use App\Services\Registration\RegistrationTermsResolver;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/KioskSetup.php';

const CONCIERGE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/**
 * A live property with the Concierge feature on and a Confirmed stay `R-100` arriving on the frozen day for two nights in
 * room 101. Its guest is Aisha Rahman. The card is not required and nothing is due, so the first step is the ID. `token`
 * is the stay's own link token.
 *
 * @param  array<string, mixed>  $unit
 * @param  array<string, mixed>  $guest
 * @return array{property: Property, unit: ReservationUnit, guest: Guest, room: Room, token: string}
 */
function conciergeSetup(array $unit = [], array $guest = []): array
{
    $setup = kioskStaySetup($unit, $guest);
    Feature::for($setup['property'])->activate(Concierge::class);
    $setup['property']->settings->forceFill(['card_required_for_check_in' => false, 'security_deposit_amount' => '0.00'])->save();

    $room = Room::factory()->create(['property_id' => $setup['property']->id, 'room_type_id' => $setup['unit']->room_type_id, 'number' => '101']);

    foreach (['2026-10-14', '2026-10-15'] as $date) {
        UnitNight::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'date' => $date, 'room_id' => $room->id]);
    }

    return [
        'property' => $setup['property'],
        'unit' => $setup['unit'],
        'guest' => $setup['guest'],
        'room' => $room,
        'token' => basename(app(IssueBookingPreCheckInToken::class)($setup['unit'])),
    ];
}

/**
 * A guest checked in to room 101, arrived on 2026-10-12 and leaving on the frozen day (2026-10-14), with an empty unit
 * folio. The Concierge feature is on and `token` is the stay's own link token.
 *
 * @return array{property: Property, unit: ReservationUnit, guest: Guest, room: Room, folio: Folio, token: string}
 */
function conciergeInStaySetup(): array
{
    $setup = kioskStaySetup(['status' => UnitStatus::CheckedIn, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'checked_in_at' => now()->subDays(2)]);
    Feature::for($setup['property'])->activate(Concierge::class);
    $room = Room::factory()->create(['property_id' => $setup['property']->id, 'room_type_id' => $setup['unit']->room_type_id, 'number' => '101']);

    foreach (['2026-10-12', '2026-10-13'] as $date) {
        UnitNight::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'date' => $date, 'room_id' => $room->id]);
    }

    $folio = Folio::factory()->for($setup['property'])->forUnit($setup['unit'])->create(['reservation_id' => $setup['reservation']->id]);

    return [
        'property' => $setup['property'],
        'unit' => $setup['unit'],
        'guest' => $setup['guest'],
        'room' => $room,
        'folio' => $folio,
        'token' => basename(app(IssueBookingPreCheckInToken::class)($setup['unit'])),
    ];
}

/** The signature Curlec's checkout gives the page for a paid order. Needs `CurlecSetup.php`. */
function conciergeSignature(string $order, string $payment): string
{
    return hash_hmac('sha256', "{$order}|{$payment}", CURLEC_KEY_SECRET);
}

/** Opens the stay page and checks it shows the given step. */
function conciergeStep(string $token, string $step): TestResponse
{
    return test()->get("/c/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/stay')
        ->where('stay.step', $step));
}

/**
 * A valid ID form body.
 *
 * @param  array<string, string>  $with
 * @return array<string, string>
 */
function conciergeIdentityBody(array $with = []): array
{
    return ['kind' => 'passport', 'number' => 'A1234567', 'full_name' => 'Aisha Rahman', 'nationality' => 'MY', ...$with];
}

/** Gives the stay's guest an ID document and a nationality, so the ID step is done. */
function conciergeIdentityDone(Guest $guest): void
{
    GuestIdentityDocument::factory()->create(['guest_id' => $guest->id]);
    $guest->forceFill(['nationality_code' => 'MY'])->save();
}

/** Seeds the registration card fields and the published terms, and returns the terms version. */
function conciergeTerms(Property $property): int
{
    app(SeedRegistrationCardFields::class)($property);
    app(SeedRegistrationTerms::class)($property, PropertyType::Hotel);

    return app(RegistrationTermsResolver::class)->resolve($property, null, null, Locale::English)->terms->version;
}
