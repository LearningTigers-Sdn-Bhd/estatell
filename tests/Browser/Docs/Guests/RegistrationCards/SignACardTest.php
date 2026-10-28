<?php

use App\Enums\Locale;
use App\Enums\RegistrationCardScope;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\UnitGuest;
use App\Services\Registration\TermsRenderer;

if (! function_exists('registrationCardsPrimaryGuest')) {
    /**
     * The primary guest of the reservation's first unit, the one the card page opens for.
     */
    function registrationCardsPrimaryGuest(Reservation $reservation): UnitGuest
    {
        return UnitGuest::query()
            ->whereHas('reservationUnit', fn ($units) => $units->where('reservation_id', $reservation->id))
            ->where('is_primary', true)
            ->orderBy('id')
            ->firstOrFail();
    }
}

if (! function_exists('registrationCardsSign')) {
    /**
     * Sign the card the way the guest screen does: with the English terms, every box ticked and a one-pixel signature.
     */
    function registrationCardsSign($test, Property $property, UnitGuest $unitGuest, string $name): void
    {
        $body = $property->termsDocuments()->where('is_default', true)->firstOrFail()->versions()->forLocale(Locale::English)->orderByDesc('version')->firstOrFail()->body;

        $test->post(route('registration.sign', [$property->code, $unitGuest]), [
            'full_name' => $name,
            'nationality_code' => 'MY',
            'phone' => '+60123456789',
            'identity' => ['type' => 'mykad', 'number' => '900101-14-1234'],
            'locale' => 'en',
            'ticks' => array_keys(app(TermsRenderer::class)->items($body)),
            'signer_name' => $name,
            'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        ])->assertRedirect();
    }
}

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
    $this->reservation = $this->singleArrivalToday();
    $this->unitGuest = registrationCardsPrimaryGuest($this->reservation);
});

test('open the card from the guests tab', function () {
    $name = $this->unitGuest->guest->full_name;

    $page = $this->open(route('reservations.guests', [$this->property, $this->reservation]))
        ->click('button[aria-label="Actions for '.$name.'"]')
        ->waitForText('Registration');

    $this->shoot($page, 'guests-tab-menu', badges: ['Registration card']);
});

test('staff card page', function () {
    $page = $this->open(route('registration.show', [$this->property, $this->unitGuest]), height: 1000)
        ->assertSee('Before handing over');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'card-page', badges: ['Print blank form', 'Hand to guest', 'Before handing over']);
});

test('guest screen', function () {
    $page = $this->open(route('registration.guest', [$this->property, $this->unitGuest]), height: 1700)
        ->assertSee('Sign card');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'guest-screen', badges: ['Agree to all', 'Sign card'], badgesFromLeft: 0);
});

test('thank-you screen with another guest to go', function () {
    $this->property->settings->update(['registration_card_scope' => RegistrationCardScope::EveryAdult]);
    $unit = $this->unitGuest->reservationUnit;

    foreach (['Nurul Huda', 'Ali Hassan'] as $name) {
        $guest = Guest::factory()->create(['property_id' => $this->property->id, 'full_name' => $name]);
        UnitGuest::factory()->for($unit)->create(['guest_id' => $guest->id]);
    }

    registrationCardsSign($this, $this->property, $this->unitGuest, $this->unitGuest->guest->full_name);

    $page = $this->open(route('registration.done', [$this->property, $this->unitGuest]))
        ->assertSee('Thank you.');

    $this->shoot($page, 'thank-you', badges: ['Continue'], badgesFromLeft: 0);
});
