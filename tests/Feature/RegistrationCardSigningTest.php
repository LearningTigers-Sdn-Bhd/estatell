<?php

use App\Actions\Registration\PrepareRegistrationCard;
use App\Actions\Registration\SaveRegistrationCardDraft;
use App\Actions\Registration\SeedRegistrationCardFields;
use App\Actions\Registration\SeedRegistrationTerms;
use App\Actions\Registration\SignRegistrationCard;
use App\Actions\Registration\VoidRegistrationCard;
use App\Enums\DefaultRoleEnum;
use App\Enums\Locale;
use App\Enums\PropertyType;
use App\Enums\RegistrationCardScope;
use App\Enums\RegistrationCardStatus;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RegistrationCard;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Models\User;
use App\Services\Registration\RegistrationCardLabels;
use App\Services\Registration\RegistrationCardRequirement;
use App\Services\Registration\RegistrationCardStay;
use App\Services\Registration\TermsRenderer;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

const CARD_SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/** @return array{owner: User, property: Property, unit: ReservationUnit, unitGuest: UnitGuest, guest: Guest} */
function cardSetup(?User $user = null): array
{
    $owner = $user ?? User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['registration_card_scope' => RegistrationCardScope::PrimaryOnly]);
    app(SeedRegistrationCardFields::class)($property);
    app(SeedRegistrationTerms::class)($property, PropertyType::Hotel);
    $unit = ReservationUnit::factory()->for(Reservation::factory()->for($property))->withNights()->create(['adults' => 2]);
    $unitGuest = UnitGuest::factory()->for($unit)->primary()->create();

    return ['owner' => $owner, 'property' => $property, 'unit' => $unit, 'unitGuest' => $unitGuest, 'guest' => $unitGuest->guest];
}

/** @return list<int> */
function cardTicks(Property $property, Locale $locale = Locale::English): array
{
    $body = $property->termsDocuments()->where('is_default', true)->firstOrFail()->versions()->forLocale($locale)->orderByDesc('version')->firstOrFail()->body;

    return array_keys(app(TermsRenderer::class)->items($body));
}

/** @return array<string, mixed> */
function cardPayload(array $setup, array $changes = []): array
{
    return [
        'full_name' => 'Aisyah Rahman',
        'nationality_code' => 'MY',
        'phone' => '+60123456789',
        'identity' => ['type' => 'mykad', 'number' => '900101-14-1234'],
        'locale' => 'ms',
        'ticks' => cardTicks($setup['property'], Locale::Malay),
        'signer_name' => 'Aisyah Rahman',
        'signature' => CARD_SIGNATURE,
        ...$changes,
    ];
}

function signUrl(array $setup): string
{
    return route('registration.sign', [$setup['property']->code, $setup['unitGuest']]);
}

test('opening the card starts one draft and reuses it', function () {
    $setup = cardSetup();

    $this->actingAs($setup['owner'])
        ->get(route('registration.show', [$setup['property']->code, $setup['unitGuest']]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('registration/card')
            ->where('card.status.value', 'draft')
            ->where('card.locale', 'en')
            ->where('card.guestName', $setup['guest']->full_name)
            ->has('card.checklist')
            ->has('card.labels')
            ->where('card.fields.0.field', 'full_name'));
    $this->get(route('registration.show', [$setup['property']->code, $setup['unitGuest']]))->assertOk();

    expect(RegistrationCard::query()->count())->toBe(1);
});

test('the language in the URL changes the terms and the labels', function () {
    $setup = cardSetup();

    $this->actingAs($setup['owner'])
        ->get(route('registration.show', [$setup['property']->code, $setup['unitGuest'], 'locale' => 'ms']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('card.locale', 'ms')
            ->where('card.labels.Full name', 'Nama penuh')
            ->where('card.localeOptions.1.value', 'ms'));
});

test('a card page of another property is not found and a user without the permission is refused', function () {
    $setup = cardSetup();
    $other = cardSetup();

    $this->actingAs($setup['owner'])->get(route('registration.show', [$setup['property']->code, $other['unitGuest']]))->assertNotFound();
    $this->post(route('registration.sign', [$setup['property']->code, $other['unitGuest']]), cardPayload($setup))->assertNotFound();
    $this->get(route('registration.guest', [$setup['property']->code, $other['unitGuest']]))->assertNotFound();
    $this->get(route('registration.done', [$setup['property']->code, $other['unitGuest']]))->assertNotFound();
    $this->put(route('registration.update', [$setup['property']->code, $other['unitGuest']]), ['vehicle_plate' => 'X'])->assertNotFound();

    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $this->actingAs($housekeeping)->get(route('registration.show', [$housekeeping->property->code, $setup['unitGuest']]))->assertForbidden();
    $this->get(route('registration.guest', [$housekeeping->property->code, $setup['unitGuest']]))->assertForbidden();
    $this->put(route('registration.update', [$housekeeping->property->code, $setup['unitGuest']]), [])->assertForbidden();
});

test('a cancelled unit cannot have a card', function () {
    $setup = cardSetup();
    $setup['unit']->forceFill(['status' => 'cancelled'])->save();

    $this->actingAs($setup['owner'])
        ->get(route('registration.show', [$setup['property']->code, $setup['unitGuest']]))
        ->assertRedirect(route('reservations.show', [$setup['property']->code, $setup['unit']->reservation_id]));

    expect(RegistrationCard::query()->count())->toBe(0);
});

test('signing saves the guest, freezes the snapshot, stores the signature and numbers the card', function () {
    $setup = cardSetup();

    $this->actingAs($setup['owner'])
        ->post(signUrl($setup), cardPayload($setup))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('registration.done', [$setup['property']->code, $setup['unitGuest']]));

    $card = RegistrationCard::query()->sole()->refresh();
    $guest = $setup['guest']->refresh();

    expect($card->status)->toBe(RegistrationCardStatus::Signed)
        ->and($card->number)->not->toBeNull()
        ->and($card->terms->locale)->toBe(Locale::Malay)
        ->and($card->signer_name)->toBe('Aisyah Rahman')
        ->and($card->getFirstMedia(RegistrationCard::SIGNATURE))->not->toBeNull()
        ->and($card->snapshot['locale'])->toBe('ms')
        ->and($card->snapshot['terms']['items'])->each(fn ($item) => $item->ticked_at->not->toBeNull())
        ->and(collect($card->snapshot['fields'])->firstWhere('field', 'full_name')['value'])->toBe('Aisyah Rahman')
        ->and(collect($card->snapshot['fields'])->firstWhere('field', 'identity_document')['value'])->toContain('1234')
        ->and(collect($card->snapshot['fields'])->pluck('field')->all())->not->toContain('rate_per_night')
        ->and($guest->full_name)->toBe('Aisyah Rahman')
        ->and($guest->preferred_locale)->toBe(Locale::Malay)
        ->and($guest->documents()->count())->toBe(1)
        ->and($card->identity_document_id)->toBe($guest->documents()->sole()->id)
        ->and(Activity::query()->where('event', 'registration_card.signed')->count())->toBe(1);
});

test('the signed snapshot does not change when the guest changes later', function () {
    $setup = cardSetup();
    $this->actingAs($setup['owner'])->post(signUrl($setup), cardPayload($setup));
    $before = RegistrationCard::query()->sole()->snapshot;

    $setup['guest']->refresh()->update(['full_name' => 'Someone Else']);

    expect(RegistrationCard::query()->sole()->snapshot)->toBe($before);
});

test('a signed card cannot be signed again', function () {
    $setup = cardSetup();
    $this->actingAs($setup['owner'])->post(signUrl($setup), cardPayload($setup));
    $number = RegistrationCard::query()->sole()->number;

    $this->post(signUrl($setup), cardPayload($setup, ['full_name' => 'Changed Name']))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(RegistrationCard::query()->count())->toBe(1)
        ->and(RegistrationCard::query()->sole()->number)->toBe($number)
        ->and($setup['guest']->refresh()->full_name)->toBe('Aisyah Rahman');
});

test('signing is refused unless every checklist item is ticked', function () {
    $setup = cardSetup();
    $ticks = cardTicks($setup['property'], Locale::Malay);

    $this->actingAs($setup['owner'])
        ->post(signUrl($setup), cardPayload($setup, ['ticks' => array_slice($ticks, 1)]))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
    $this->post(signUrl($setup), cardPayload($setup, ['ticks' => []]))->assertSessionHas('inertia.flash_data.toast.type', 'error');

    $card = RegistrationCard::query()->sole();

    expect($card->status)->toBe(RegistrationCardStatus::Draft)
        ->and($card->number)->toBeNull()
        ->and($setup['guest']->refresh()->preferred_locale)->toBeNull()
        ->and(Activity::query()->where('event', 'registration_card.signed')->count())->toBe(0);
});

test('ticks for a version with no checklist items are not needed', function () {
    $setup = cardSetup();
    $document = $setup['property']->termsDocuments()->sole();
    $document->versions()->create(['locale' => 'en', 'version' => 2, 'body' => 'No boxes here.', 'published_at' => now()]);

    $this->actingAs($setup['owner'])
        ->post(signUrl($setup), cardPayload($setup, ['locale' => 'en', 'ticks' => []]))
        ->assertRedirect(route('registration.done', [$setup['property']->code, $setup['unitGuest']]));
});

test('a signature that is not a PNG is refused', function () {
    $setup = cardSetup();

    $this->actingAs($setup['owner'])
        ->post(signUrl($setup), cardPayload($setup, ['signature' => 'data:image/png;base64,'.base64_encode('not an image')]))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
    $this->post(signUrl($setup), cardPayload($setup, ['signature' => 'data:text/html;base64,PGI+']))->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(RegistrationCard::query()->sole()->status)->toBe(RegistrationCardStatus::Draft);
});

test('a language with no published terms is refused', function () {
    $setup = cardSetup();
    $setup['property']->termsDocuments()->sole()->versions()->forLocale(Locale::Malay)->delete();

    $this->actingAs($setup['owner'])
        ->post(signUrl($setup), cardPayload($setup, ['locale' => 'ms']))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});

test('required fields follow the card layout', function () {
    $setup = cardSetup();
    $setup['property']->registrationCardFields()->where('field', 'email')->update(['visibility' => 'required']);
    $setup['property']->registrationCardFields()->where('field', 'phone')->update(['visibility' => 'hidden']);

    $this->actingAs($setup['owner'])
        ->post(signUrl($setup), cardPayload($setup, ['phone' => 'not a phone']))
        ->assertSessionHasErrors('email')
        ->assertSessionDoesntHaveErrors('phone');

    expect(RegistrationCard::query()->sole()->status)->toBe(RegistrationCardStatus::Draft);
});

test('a missing ID document is refused when the field is Required, and an existing one can be reused', function () {
    $setup = cardSetup();
    $document = GuestIdentityDocument::factory()->create(['guest_id' => $setup['guest']->id]);

    $this->actingAs($setup['owner'])
        ->post(signUrl($setup), cardPayload($setup, ['identity' => null]))
        ->assertSessionHasErrors('identity_document_id');
    $this->post(signUrl($setup), cardPayload($setup, ['identity' => null, 'identity_document_id' => $document->id, 'vehicle_plate' => 'WXY 1234']))->assertSessionHasNoErrors();

    expect(RegistrationCard::query()->sole()->identity_document_id)->toBe($document->id);
});

test('another guest\'s ID document cannot be used', function () {
    $setup = cardSetup();
    $foreign = GuestIdentityDocument::factory()->create();

    $this->actingAs($setup['owner'])
        ->post(signUrl($setup), cardPayload($setup, ['identity' => null, 'identity_document_id' => $foreign->id]))
        ->assertSessionHasErrors('identity_document_id');
});

test('voiding a signed card keeps its record and lets a new card be signed', function () {
    $setup = cardSetup();
    $this->actingAs($setup['owner'])->post(signUrl($setup), cardPayload($setup));
    $first = RegistrationCard::query()->sole();

    $this->post(route('registration.void', [$setup['property']->code, $setup['unitGuest']]), ['reason' => 'Wrong name'])
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    $first->refresh();
    expect($first->status)->toBe(RegistrationCardStatus::Void)
        ->and($first->void_reason)->toBe('Wrong name')
        ->and($first->snapshot)->not->toBeNull()
        ->and(Activity::query()->where('event', 'registration_card.voided')->count())->toBe(1);

    $this->post(signUrl($setup), cardPayload($setup))->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect(RegistrationCard::query()->count())->toBe(2)
        ->and(RegistrationCard::query()->where('status', 'signed')->count())->toBe(1);
});

test('voiding needs a reason and a card that is not void', function () {
    $setup = cardSetup();
    $url = route('registration.void', [$setup['property']->code, $setup['unitGuest']]);

    $this->actingAs($setup['owner'])->post($url, ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post($url, ['reason' => 'No card yet'])->assertSessionHas('inertia.flash_data.toast.type', 'error');

    $card = app(PrepareRegistrationCard::class)($setup['unitGuest']);

    expect(app(VoidRegistrationCard::class)($setup['owner'], $card, 'Reason'))->toBeTrue()
        ->and(app(VoidRegistrationCard::class)($setup['owner'], $card, 'Again'))->toBeFalse()
        ->and(RegistrationCardStatus::Void->canTransitionTo(RegistrationCardStatus::Draft))->toBeFalse();
});

test('front desk can sign but not void, and finance can do neither', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $setup = cardSetup($frontDesk);

    $this->actingAs($frontDesk)->get(route('registration.show', [$setup['property']->code, $setup['unitGuest']]))->assertOk();
    $this->post(route('registration.void', [$setup['property']->code, $setup['unitGuest']]), ['reason' => 'x'])->assertForbidden();

    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create();
    $this->actingAs($finance)->get(route('registration.show', [$finance->property->code, $setup['unitGuest']]))->assertForbidden();
});

test('only one open card exists for a unit guest', function () {
    $setup = cardSetup();
    app(PrepareRegistrationCard::class)($setup['unitGuest']);

    expect(fn () => RegistrationCard::factory()->create(['unit_guest_id' => $setup['unitGuest']->id]))->toThrow(UniqueConstraintViolationException::class)
        ->and(app(PrepareRegistrationCard::class)($setup['unitGuest'])->id)->toBe(RegistrationCard::query()->sole()->id);
});

test('the requirement follows the card scope and leaves out signed guests and minors', function () {
    $setup = cardSetup();
    $adult = UnitGuest::factory()->for($setup['unit'])->create();
    $adult->guest->update(['date_of_birth' => '1990-01-01']);
    $child = UnitGuest::factory()->for($setup['unit'])->create();
    $child->guest->update(['date_of_birth' => '2020-01-01']);
    $requirement = app(RegistrationCardRequirement::class);

    expect($requirement->missingFor($setup['unit'], $setup['property'])->pluck('id')->all())->toBe([$setup['unitGuest']->id]);

    $setup['property']->settings->update(['registration_card_scope' => RegistrationCardScope::EveryAdult]);
    $property = $setup['property']->refresh();

    expect($requirement->missingFor($setup['unit'], $property)->pluck('id')->sort()->values()->all())->toBe([$setup['unitGuest']->id, $adult->id]);

    RegistrationCard::factory()->create(['unit_guest_id' => $adult->id, 'status' => 'signed']);

    expect($requirement->missingFor($setup['unit'], $property)->pluck('id')->all())->toBe([$setup['unitGuest']->id]);
});

test('the stay lines come from the unit', function () {
    $setup = cardSetup();
    $values = app(RegistrationCardStay::class)->valuesFor($setup['unitGuest'], $setup['property']);

    expect($values['booking_number'])->toBe($setup['unit']->reservation->reference)
        ->and($values['check_in'])->toBe('02 Nov 2026, 15:00')
        ->and($values['check_out'])->toBe('05 Nov 2026, 11:00')
        ->and($values['nights'])->toBe('3')
        ->and($values['guest_count'])->toBe('Adults 2')
        ->and($values['room'])->toBe($setup['unit']->roomType->name)
        ->and($values['rate_per_night'])->toBe('MYR 100.00')
        ->and($values['total_charges'])->toBe('MYR 300.00');
});

test('the labels come in the chosen language and fall back to English', function () {
    $labels = app(RegistrationCardLabels::class);

    expect($labels->for(Locale::Malay)['Full name'])->toBe('Nama penuh')
        ->and($labels->for(Locale::English)['Full name'])->toBe('Full name');
});

test('the Guests tab links to the card only for staff who may sign', function () {
    $setup = cardSetup();

    $this->actingAs($setup['owner'])
        ->get(route('reservations.guests', [$setup['property']->code, $setup['unit']->reservation_id]))
        ->assertInertia(fn (Assert $page) => $page->where('guests.1.cardUnitGuestId', $setup['unitGuest']->id));

    $viewer = User::factory()->withRole(DefaultRoleEnum::Finance)->create();
    $other = cardSetup($viewer);

    $this->actingAs($viewer)
        ->get(route('reservations.guests', [$other['property']->code, $other['unit']->reservation_id]))
        ->assertInertia(fn (Assert $page) => $page->where('guests.1.cardUnitGuestId', null));
});

test('every action refuses a card that is not a draft, without writing', function () {
    $setup = cardSetup();
    $card = app(PrepareRegistrationCard::class)($setup['unitGuest']);
    $card->forceFill(['status' => RegistrationCardStatus::Void])->save();

    $signed = app(SignRegistrationCard::class)($setup['owner'], $card, [
        'profile' => ['full_name' => 'X'], 'identity' => null, 'identity_document_id' => null, 'vehicle_plate' => null,
        'marketing_consent' => false, 'locale' => Locale::English, 'ticks' => cardTicks($setup['property']), 'signature' => CARD_SIGNATURE, 'signer_name' => 'X',
    ]);

    $saved = app(SaveRegistrationCardDraft::class)($setup['owner'], $card, ['identity' => null, 'identity_document_id' => null, 'vehicle_plate' => 'WXY 1']);

    expect($signed)->toBeFalse()
        ->and($saved)->toBeFalse()
        ->and($setup['guest']->refresh()->full_name)->not->toBe('X')
        ->and($card->refresh()->vehicle_plate)->toBeNull();
});

test('the staff page shows a draft read-only and the guest screen shows the card to sign', function () {
    $setup = cardSetup();

    $this->actingAs($setup['owner'])
        ->get(route('registration.show', [$setup['property']->code, $setup['unitGuest']]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('registration/card')
            ->where('card.draftFields.0.value', $setup['guest']->full_name));

    $this->get(route('registration.guest', [$setup['property']->code, $setup['unitGuest'], 'locale' => 'ms']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('registration/guest')
            ->where('card.locale', 'ms')
            ->where('card.walkPosition', 1)
            ->where('card.walkTotal', 1)
            ->where('card.labels.Agree to all', 'Setuju semua'));
});

test('staff save the ID document and the vehicle plate on a draft, and the guest screen keeps them', function () {
    $setup = cardSetup();
    $setup['property']->registrationCardFields()->where('field', 'vehicle_plate')->update(['visibility' => 'optional']);
    $url = route('registration.update', [$setup['property']->code, $setup['unitGuest']]);

    $this->actingAs($setup['owner'])
        ->put($url, ['identity' => ['type' => 'mykad', 'number' => '900101-14-1234'], 'vehicle_plate' => 'WXY 1234'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    $card = RegistrationCard::query()->sole();
    $document = $setup['guest']->documents()->sole();

    expect($card->identity_document_id)->toBe($document->id)
        ->and($card->vehicle_plate)->toBe('WXY 1234');

    $this->get(route('registration.guest', [$setup['property']->code, $setup['unitGuest']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('card.cardDocumentId', $document->id)
            ->where('card.values.vehicle_plate', 'WXY 1234'));

    $this->post(signUrl($setup), cardPayload($setup, ['identity' => null, 'identity_document_id' => $document->id, 'vehicle_plate' => 'WXY 1234']))
        ->assertSessionHasNoErrors();

    expect($card->refresh()->status)->toBe(RegistrationCardStatus::Signed)
        ->and($card->identity_document_id)->toBe($document->id)
        ->and($setup['guest']->documents()->count())->toBe(1);

    $this->put($url, ['vehicle_plate' => 'Changed'])->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect($card->refresh()->vehicle_plate)->toBe('WXY 1234');
});

test('the draft cannot use another guest\'s ID document', function () {
    $setup = cardSetup();
    $stranger = GuestIdentityDocument::factory()->create();

    $this->actingAs($setup['owner'])
        ->put(route('registration.update', [$setup['property']->code, $setup['unitGuest']]), ['identity_document_id' => $stranger->id])
        ->assertSessionHasErrors('identity_document_id');
});

test('after signing, the thank-you screen names the next guest of the unit until none is left', function () {
    $setup = cardSetup();
    $setup['property']->settings->update(['registration_card_scope' => RegistrationCardScope::EveryAdult]);
    $second = UnitGuest::factory()->for($setup['unit'])->create();
    $done = route('registration.done', [$setup['property']->code, $setup['unitGuest']]);

    $this->actingAs($setup['owner'])->post(signUrl($setup), cardPayload($setup))->assertRedirect($done);

    $this->get($done)->assertInertia(fn (Assert $page) => $page
        ->component('registration/done')
        ->where('done.nextUnitGuestId', $second->id)
        ->where('done.nextGuestName', $second->guest->full_name)
        ->where('done.nextPosition', 2)
        ->where('done.total', 2)
        ->where('done.locale', 'ms')
        ->where('done.labels.Continue', 'Teruskan'));

    $this->get(route('registration.guest', [$setup['property']->code, $setup['unitGuest']]))->assertRedirect($done);

    $this->post(route('registration.sign', [$setup['property']->code, $second]), cardPayload($setup, ['full_name' => 'Ali Hassan', 'signer_name' => 'Ali Hassan']))
        ->assertRedirect(route('registration.done', [$setup['property']->code, $second]));

    $this->get(route('registration.done', [$setup['property']->code, $second]))
        ->assertInertia(fn (Assert $page) => $page->where('done.nextUnitGuestId', null));
});

test('a refused signature goes back to the guest screen in the same language', function () {
    $setup = cardSetup();

    $this->actingAs($setup['owner'])
        ->post(signUrl($setup), cardPayload($setup, ['ticks' => []]))
        ->assertRedirect(route('registration.guest', [$setup['property']->code, $setup['unitGuest'], 'locale' => 'ms']))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});
