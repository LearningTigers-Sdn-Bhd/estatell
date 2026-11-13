<?php

use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Enums\PaymentPurpose;
use App\Enums\RegistrationCardStatus;
use App\Enums\UnitStatus;
use App\Features\Concierge;
use App\Models\BookingPreCheckInToken;
use App\Models\Folio;
use App\Models\GuestIdentityDocument;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RegistrationCard;
use App\Services\Registration\RegistrationCardFieldRules;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ConciergeSetup.php';

it('answers 404 for an unknown or expired link, a feature that is off and a suspended property', function () {
    ['property' => $property, 'token' => $token] = conciergeSetup();

    $this->get('/c/'.str_repeat('a', 43))->assertNotFound()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/error')->where('status', 404)->where('recoveryUrl', null)->missing('property')->missing('stay'));
    $this->get('/c/short')->assertNotFound();

    $this->travelTo(now()->addDays(10));
    $this->get("/c/{$token}")->assertNotFound()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/error')->where('status', 404)->where('recoveryUrl', null)->missing('property')->missing('stay'));
    $this->travelBack();

    Feature::for($property)->deactivate(Concierge::class);
    $this->get("/c/{$token}")->assertNotFound();

    Feature::for($property)->activate(Concierge::class);
    $property->forceFill(['suspended_at' => now()])->save();
    $this->get("/c/{$token}")->assertNotFound();
});

it('starts at the ID and sends only the first name', function () {
    ['token' => $token] = conciergeSetup();

    conciergeStep($token, 'identity')->assertInertia(fn (Assert $page) => $page
        ->where('stay.guestFirstName', 'Aisha')
        ->where('stay.roomNumber', null)
        ->where('stay.pin', null)
        ->where('stay.property.name', 'Palm Resort'));
});

it('fills the ID step with what is already saved for the guest, and only on that step', function () {
    ['token' => $token, 'guest' => $guest] = conciergeSetup();
    $guest->forceFill(['nationality_code' => 'MY'])->save();

    conciergeStep($token, 'identity')->assertInertia(fn (Assert $page) => $page
        ->where('stay.identity.fullName', $guest->full_name)
        ->where('stay.identity.nationalityCode', 'MY')
        ->where('stay.identity.documentNumberHint', null)
        ->where('stay.identity.hasDateOfBirth', false));

    $this->post("/c/{$token}/identity", conciergeIdentityBody(['number' => 'A1234567']));

    $this->get("/c/{$token}")->assertInertia(fn (Assert $page) => $page->where('stay.identity', null));
});

it('saves the typed ID, fills the guest and moves to check-in', function () {
    ['token' => $token, 'guest' => $guest] = conciergeSetup();

    $this->post("/c/{$token}/identity", conciergeIdentityBody(['date_of_birth' => '1990-05-01', 'gender' => 'female']))->assertRedirect("/c/{$token}");

    $guest->refresh();
    expect($guest->nationality_code)->toBe('MY')
        ->and($guest->documents()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'concierge.identity_received')->sole()->properties->all())->toBe(['property_id' => $guest->property_id, 'type' => 'passport']);
    conciergeStep($token, 'check_in');
});

it('does not add the same document twice', function () {
    ['token' => $token, 'guest' => $guest] = conciergeSetup();

    $this->post("/c/{$token}/identity", conciergeIdentityBody());
    $this->post("/c/{$token}/identity", conciergeIdentityBody());

    expect(GuestIdentityDocument::query()->where('guest_id', $guest->id)->count())->toBe(1);
});

it('refuses an ID with a bad form and saves nothing', function (array $body, string $field) {
    ['token' => $token, 'guest' => $guest] = conciergeSetup();

    $this->post("/c/{$token}/identity", conciergeIdentityBody($body))->assertSessionHasErrors($field);

    expect($guest->documents()->count())->toBe(0);
})->with([
    'no name' => [['full_name' => ''], 'full_name'],
    'short number' => [['number' => 'A1'], 'number'],
    'MyKad that is not 12 digits' => [['kind' => 'my_kad', 'number' => '12345'], 'number'],
    'unknown kind' => [['kind' => 'licence'], 'kind'],
]);

it('keeps asking for a nationality when the property needs one', function () {
    ['token' => $token, 'guest' => $guest] = conciergeSetup();
    conciergeIdentityDone($guest);
    $guest->forceFill(['nationality_code' => null])->save();
    $this->mock(RegistrationCardFieldRules::class, fn ($mock) => $mock->shouldReceive('nationalityIsNeededForTax')->andReturn(true));

    conciergeStep($token, 'identity')->assertInertia(fn (Assert $page) => $page->where('stay.nationalityRequired', true));
});

it('asks for the registration card when the property requires it, and signs it', function () {
    ['token' => $token, 'guest' => $guest, 'property' => $property, 'unit' => $unit] = conciergeSetup();
    $property->settings->forceFill(['card_required_for_check_in' => true])->save();
    $version = conciergeTerms($property);
    conciergeIdentityDone($guest);

    conciergeStep($token, 'card')->assertInertia(fn (Assert $page) => $page
        ->where('stay.terms.version', $version)
        ->where('stay.terms.text', fn ($text) => str_contains($text, 'Palm Resort') && ! str_contains($text, '{{')));

    $this->post("/c/{$token}/sign", ['terms_version' => $version, 'signature_png' => CONCIERGE_PNG])->assertRedirect("/c/{$token}");

    $card = RegistrationCard::query()->sole();
    expect($card->status)->toBe(RegistrationCardStatus::Signed)
        ->and($card->signer_name)->toBe('Aisha Rahman');
    conciergeStep($token, 'check_in');
});

it('refuses a signature for terms that changed and a signature that is not a PNG', function () {
    ['token' => $token, 'guest' => $guest, 'property' => $property] = conciergeSetup();
    $property->settings->forceFill(['card_required_for_check_in' => true])->save();
    $version = conciergeTerms($property);
    conciergeIdentityDone($guest);

    $this->post("/c/{$token}/sign", ['terms_version' => $version + 1, 'signature_png' => CONCIERGE_PNG]);
    $this->post("/c/{$token}/sign", ['terms_version' => $version, 'signature_png' => base64_encode('not a png')]);

    expect(RegistrationCard::query()->where('status', RegistrationCardStatus::Signed)->count())->toBe(0);
    conciergeStep($token, 'card');
});

it('asks for the payment that is due, then moves on once it is held', function () {
    ['token' => $token, 'guest' => $guest, 'property' => $property, 'unit' => $unit] = conciergeSetup();
    $property->settings->forceFill(['security_deposit_amount' => '100.00'])->save();
    conciergeIdentityDone($guest);

    conciergeStep($token, 'payment')->assertInertia(fn (Assert $page) => $page->where('stay.shortfall', '100.00'));

    $folio = Folio::factory()->forUnit($unit)->create(['property_id' => $property->id, 'reservation_id' => $unit->reservation_id]);
    Payment::factory()->create(['folio_id' => $folio->id, 'amount' => '100.00', 'purpose' => PaymentPurpose::Advance]);

    conciergeStep($token, 'check_in');
});

it('checks the guest in with the front desk rules and shows the key step', function () {
    ['token' => $token, 'guest' => $guest, 'unit' => $unit, 'property' => $property] = conciergeSetup();
    conciergeIdentityDone($guest);

    $this->post("/c/{$token}/check-in")->assertRedirect("/c/{$token}");

    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn)->and($unit->checked_in_at)->not->toBeNull();
    $marker = Activity::query()->where('event', 'concierge.checked_in')->sole();
    expect($marker->properties->all())->toBe(['property_id' => $property->id])
        ->and(Activity::query()->where('event', 'reservation.unit_checked_in')->sole()->causer_id)->toBeNull();
    conciergeStep($token, 'key')->assertInertia(fn (Assert $page) => $page
        ->where('stay.roomNumber', '101')
        ->where('stay.pin.status', 'failed'));
});

it('asks the guest to be at the property to check in, when the property turns that on', function () {
    ['token' => $token, 'guest' => $guest, 'unit' => $unit, 'property' => $property] = conciergeSetup();
    $property->forceFill(['latitude' => 3.1390, 'longitude' => 101.6869])->save();
    $property->settings->forceFill(['concierge_location_check_enabled' => true])->save();
    conciergeIdentityDone($guest);

    conciergeStep($token, 'check_in')->assertInertia(fn (Assert $page) => $page->where('stay.locationRequired', true));

    $this->post("/c/{$token}/check-in")->assertInertiaFlash('toast.message', 'Allow location access so we can see you are at the property, then try again.');
    $this->post("/c/{$token}/check-in", ['latitude' => 3.1590, 'longitude' => 101.6869])->assertInertiaFlash('toast.message', 'Check-in opens when you are at the property. Try again when you arrive.');
    expect($unit->refresh()->status)->toBe(UnitStatus::Confirmed);

    $this->post("/c/{$token}/check-in", ['latitude' => 3.13905, 'longitude' => 101.68695])->assertRedirect("/c/{$token}");
    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn);
});

it('does not ask for the location while the switch is off or the property has none', function () {
    ['token' => $token, 'guest' => $guest, 'unit' => $unit, 'property' => $property] = conciergeSetup();
    $property->settings->forceFill(['concierge_location_check_enabled' => true])->save();
    $property->forceFill(['latitude' => null, 'longitude' => null])->save();
    conciergeIdentityDone($guest);

    $this->post("/c/{$token}/check-in")->assertRedirect("/c/{$token}");

    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn);
});

it('checks in once and answers the same room again on a retry', function () {
    ['token' => $token, 'guest' => $guest] = conciergeSetup();
    conciergeIdentityDone($guest);

    $this->post("/c/{$token}/check-in");
    $this->post("/c/{$token}/check-in");

    expect(Activity::query()->where('event', 'concierge.checked_in')->count())->toBe(1);
});

it('does not check in a guest who still owes the deposit', function () {
    ['token' => $token, 'guest' => $guest, 'unit' => $unit, 'property' => $property] = conciergeSetup();
    $property->settings->forceFill(['security_deposit_amount' => '100.00'])->save();
    conciergeIdentityDone($guest);

    $this->post("/c/{$token}/check-in")->assertRedirect("/c/{$token}");

    expect($unit->refresh()->status)->toBe(UnitStatus::Confirmed)
        ->and(Activity::query()->where('event', 'concierge.checked_in')->count())->toBe(0);
});

it('does not check in a guest whose card is still unsigned', function () {
    ['token' => $token, 'guest' => $guest, 'unit' => $unit, 'property' => $property] = conciergeSetup();
    $property->settings->forceFill(['card_required_for_check_in' => true])->save();
    conciergeTerms($property);
    conciergeIdentityDone($guest);

    $this->post("/c/{$token}/check-in");

    expect($unit->refresh()->status)->toBe(UnitStatus::Confirmed);
});

it('does not show the room or PIN of a stay the front desk checked in', function () {
    ['token' => $token, 'unit' => $unit] = conciergeSetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);

    conciergeStep($token, 'home')->assertInertia(fn (Assert $page) => $page->where('stay.roomNumber', null)->where('stay.pin', null));
    expect($unit->status)->toBe(UnitStatus::CheckedIn);
});

it('stops showing the key after the window that follows a concierge check-in', function () {
    ['token' => $token, 'guest' => $guest] = conciergeSetup();
    conciergeIdentityDone($guest);
    $this->post("/c/{$token}/check-in");
    conciergeStep($token, 'key');

    $this->travelTo(now()->addMinutes(31));

    conciergeStep($token, 'home');
});

it('lets a guest register before the arrival day, then waits on not open', function () {
    ['token' => $token, 'guest' => $guest] = conciergeSetup(['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22']);

    conciergeStep($token, 'identity')->assertInertia(fn (Assert $page) => $page->where('stay.checkInOpensLater', true));
    conciergeIdentityDone($guest);

    conciergeStep($token, 'not_open')->assertInertia(fn (Assert $page) => $page->where('stay.checkInTime', fn ($time) => $time === null || strlen($time) === 5));
    $this->post("/c/{$token}/check-in");
    conciergeStep($token, 'not_open');
});

it('holds check-in until the check-in time on the arrival day', function () {
    ['token' => $token, 'guest' => $guest, 'property' => $property] = conciergeSetup();
    conciergeIdentityDone($guest);
    $property->settings->forceFill(['check_in_time' => '23:59:00'])->save();
    $this->travelTo(now()->startOfDay()->addHour());

    conciergeStep($token, 'not_open');
    $this->post("/c/{$token}/check-in");
    conciergeStep($token, 'not_open');

    $property->settings->forceFill(['check_in_time' => '00:00:00'])->save();

    conciergeStep($token, 'check_in');
});

it('sends a stay that is no longer open to the desk', function () {
    ['token' => $token] = conciergeSetup(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);

    conciergeStep($token, 'desk');
});

it('works only on the stay of its own link', function () {
    ['token' => $token, 'unit' => $unit] = conciergeSetup();
    $other = kioskStayFor(Property::query()->findOrFail($unit->property_id), 'R-300', guest: ['full_name' => 'Ben Lee']);
    $otherToken = basename(app(IssueBookingPreCheckInToken::class)($other['unit']));

    $this->post("/c/{$token}/identity", conciergeIdentityBody(['full_name' => 'Aisha Rahman']));

    expect($other['guest']->documents()->count())->toBe(0)
        ->and(BookingPreCheckInToken::query()->where('reservation_unit_id', $other['unit']->id)->count())->toBe(1);
    conciergeStep($otherToken, 'identity')->assertInertia(fn (Assert $page) => $page->where('stay.guestFirstName', 'Ben'));
});

it('never sends the saved ID number or birth date to the page, and keeps them when the form leaves the number blank', function () {
    ['token' => $token, 'guest' => $guest] = conciergeSetup();
    $guest->forceFill(['date_of_birth' => '1990-05-01', 'nationality_code' => null])->save();
    $this->mock(RegistrationCardFieldRules::class, fn ($mock) => $mock->shouldReceive('nationalityIsNeededForTax')->andReturn(true));
    GuestIdentityDocument::factory()->passport()->create(['guest_id' => $guest->id, 'number' => 'A1234567']);

    conciergeStep($token, 'identity')->assertInertia(fn (Assert $page) => $page
        ->where('stay.identity.documentNumberHint', '•••••567')
        ->where('stay.identity.hasDateOfBirth', true)
        ->missing('stay.identity.documentNumber')
        ->missing('stay.identity.dateOfBirth'));

    $this->post("/c/{$token}/identity", conciergeIdentityBody(['kind' => 'passport', 'number' => '', 'keep_saved_document' => '1']))->assertRedirect("/c/{$token}");

    expect($guest->documents()->count())->toBe(1)
        ->and($guest->fresh()->date_of_birth->toDateString())->toBe('1990-05-01');
});
