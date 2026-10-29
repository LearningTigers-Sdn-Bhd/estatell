<?php

use App\Actions\Guests\AnonymizeGuest;
use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;

/**
 * The scanner box shows what the real bridge says, so a page that has one on load gets it removed before the shot.
 */
const PRIVACY_HIDE_SCANNER = "document.querySelectorAll('div.rounded-lg').forEach((box) => { if (box.textContent.startsWith('MyKad Smart Card Reader')) { box.remove(); } });";

/**
 * A guest of the property who is an occupant of a unit with this status and of no unit with the other status.
 */
function privacyGuestWithUnit($property, UnitStatus $status): Guest
{
    $occupants = fn (UnitStatus $wanted) => UnitGuest::query()->select('guest_id')->whereIn(
        'reservation_unit_id',
        ReservationUnit::query()->where('property_id', $property->id)->where('status', $wanted)->select('id'),
    );
    $other = $status === UnitStatus::CheckedIn ? UnitStatus::Confirmed : UnitStatus::CheckedIn;

    return Guest::query()
        ->where('property_id', $property->id)
        ->whereNull('anonymized_at')
        ->whereNull('merged_into_id')
        ->whereIn('id', $occupants($status))
        ->whereNotIn('id', $occupants($other))
        ->orderBy('id')
        ->firstOrFail();
}

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('archive button', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'full_name' => 'Hafiz Ismail', 'email' => 'hafiz.ismail@example.my']);

    $page = $this->open(route('guests.show', [$this->property, $guest]))
        ->waitForText('Hafiz Ismail');
    $page->script(PRIVACY_HIDE_SCANNER);

    $this->shoot($page, 'privacy-archive-button', badges: ['Archive']);
});

test('archive dialog', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'full_name' => 'Hafiz Ismail', 'email' => 'hafiz.ismail@example.my']);

    $page = $this->open(route('guests.show', [$this->property, $guest]))
        ->waitForText('Hafiz Ismail');
    $page->script(PRIVACY_HIDE_SCANNER);
    $page->click('Archive')
        ->waitForText('Type Confirm to continue')
        ->type('#anonymize-confirm', 'Confirm')
        ->wait(0.5);

    $this->shoot($page, 'privacy-archive-dialog', frame: '[role="alertdialog"]');
});

test('archived guest', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'full_name' => 'Hafiz Ismail', 'email' => 'hafiz.ismail@example.my']);

    $page = $this->open(route('guests.show', [$this->property, $guest]))
        ->waitForText('Hafiz Ismail')
        ->click('Archive')
        ->type('#anonymize-confirm', 'Confirm')
        ->wait(0.5)
        ->click('Archive guest')
        ->waitForText('This guest was archived on');

    $this->shoot($page, 'privacy-archived-guest');
});

test('archive refused for a guest in house', function () {
    $guest = privacyGuestWithUnit($this->property, UnitStatus::CheckedIn);

    $page = $this->open(route('guests.show', [$this->property, $guest]))
        ->waitForText($guest->full_name)
        ->click('Archive')
        ->type('#anonymize-confirm', 'Confirm')
        ->wait(0.5)
        ->click('Archive guest')
        ->waitForText('cannot be archived');
    $page->script(PRIVACY_HIDE_SCANNER);

    $this->shoot($page, 'privacy-archive-refused');
});

test('a guest with only upcoming stays can be archived', function () {
    $guest = privacyGuestWithUnit($this->property, UnitStatus::Confirmed);
    $units = UnitGuest::query()->where('guest_id', $guest->id)->count();

    expect(app(AnonymizeGuest::class)(auth()->user(), $guest))->toBeTrue();
    expect($guest->fresh()->full_name)->toBe("Archived User #{$guest->id}")
        ->and(UnitGuest::query()->where('guest_id', $guest->id)->count())->toBe($units);
});
