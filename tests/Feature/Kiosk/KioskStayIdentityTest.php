<?php

use App\Enums\Gender;
use App\Enums\IdentityDocumentType;
use App\Models\BusinessDate;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Kiosk;
use App\Models\Property;
use App\Models\Tax;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/KioskSetup.php';

function kioskMyKadBody(array $overrides = []): array
{
    return [
        'kind' => 'my_kad',
        'number' => '900101-14-1234',
        'full_name' => 'AISHA BINTI RAHMAN',
        'nationality' => 'MY',
        'date_of_birth' => '1990-01-01',
        'gender' => 'female',
        ...$overrides,
    ];
}

it('saves a MyKad as the guest identity document and fills the empty details', function () {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup(guest: ['full_name' => '']);

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody())
        ->assertOk()
        ->assertExactJson(['accepted' => true, 'nationality_required' => false]);

    $document = GuestIdentityDocument::query()->where('guest_id', $guest->id)->sole();
    expect($document->type)->toBe(IdentityDocumentType::MyKad)
        ->and($document->number)->toBe('900101141234')
        ->and($document->issuing_country_code)->toBe('MY')
        ->and($document->expires_on)->toBeNull();

    $guest->refresh();
    expect($guest->full_name)->toBe('AISHA BINTI RAHMAN')
        ->and($guest->nationality_code)->toBe('MY')
        ->and($guest->date_of_birth->toDateString())->toBe('1990-01-01')
        ->and($guest->gender)->toBe(Gender::Female)
        ->and($guest->name_key)->not->toBe('');
});

it('saves a passport with its expiry and the nationality as the issuing country', function () {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup();

    kioskPost("/stays/{$unit->id}/identity", [
        'kind' => 'passport', 'number' => 'a 1234567', 'full_name' => 'Jane Tan', 'nationality' => 'sg', 'expires_on' => '2030-05-31',
    ])->assertOk()->assertJsonPath('accepted', true);

    $document = GuestIdentityDocument::query()->where('guest_id', $guest->id)->sole();
    expect($document->type)->toBe(IdentityDocumentType::Passport)
        ->and($document->number)->toBe('A1234567')
        ->and($document->issuing_country_code)->toBe('SG')
        ->and($document->expires_on->toDateString())->toBe('2030-05-31')
        ->and($guest->refresh()->nationality_code)->toBe('SG');
});

it('accepts a body without the optional fields', function () {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup();

    kioskPost("/stays/{$unit->id}/identity", ['kind' => 'my_kad', 'number' => '900101141234', 'full_name' => 'Aisha Rahman', 'nationality' => 'MY'])
        ->assertOk()
        ->assertJsonPath('accepted', true);

    expect($guest->refresh()->date_of_birth)->toBeNull()->and($guest->gender)->toBeNull();
});

it('leaves out an optional field that is not valid and still accepts the rest', function (array $bad) {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup();

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody($bad))->assertOk()->assertJsonPath('accepted', true);

    $guest->refresh();
    expect(GuestIdentityDocument::query()->where('guest_id', $guest->id)->count())->toBe(1)
        ->and($guest->nationality_code)->toBe('MY');
    $key = array_key_first($bad);
    expect($key === 'gender' ? $guest->gender?->value === 'female' : $guest->date_of_birth?->toDateString() === '1990-01-01')->toBeFalse();
})->with([
    'gender not male or female' => [['gender' => 'other']],
    'gender a number' => [['gender' => 1]],
    'birth date not a date' => [['date_of_birth' => 'tomorrow']],
    'birth date that does not exist' => [['date_of_birth' => '1990-02-31']],
    'birth date in the future' => [['date_of_birth' => '2031-01-01']],
    'birth date wrong format' => [['date_of_birth' => '01/01/1990']],
]);

it('ignores an expiry that is not a date', function () {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup();

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(['kind' => 'passport', 'number' => 'A1234567', 'expires_on' => 'soon']))
        ->assertOk()->assertJsonPath('accepted', true);

    expect(GuestIdentityDocument::query()->where('guest_id', $guest->id)->sole()->expires_on)->toBeNull();
});

it('never overwrites a value staff entered', function () {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup(guest: [
        'full_name' => 'Aisha Rahman', 'nationality_code' => 'SG', 'date_of_birth' => '1985-05-05', 'gender' => Gender::Male,
    ]);

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody())->assertOk()->assertJsonPath('accepted', true);

    $guest->refresh();
    expect($guest->full_name)->toBe('Aisha Rahman')
        ->and($guest->nationality_code)->toBe('SG')
        ->and($guest->date_of_birth->toDateString())->toBe('1985-05-05')
        ->and($guest->gender)->toBe(Gender::Male);
});

it('updates the document of the same number instead of adding a second one, and keeps the expiry it has', function () {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup();
    GuestIdentityDocument::factory()->passport()->create(['guest_id' => $guest->id, 'number' => 'A1234567', 'issuing_country_code' => 'SG', 'expires_on' => '2031-01-01']);

    kioskPost("/stays/{$unit->id}/identity", ['kind' => 'passport', 'number' => 'a1234567', 'full_name' => 'Jane Tan', 'nationality' => 'SG'])
        ->assertOk()->assertJsonPath('accepted', true);

    $document = GuestIdentityDocument::query()->where('guest_id', $guest->id)->sole();
    expect($document->expires_on->toDateString())->toBe('2031-01-01');
});

it('says nationality_required when a foreign-only tax needs a nationality and none is known', function () {
    ['unit' => $unit, 'property' => $property] = kioskStaySetup();
    Tax::factory()->for($property)->create(['foreign_guests_only' => true]);

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(['nationality' => 'ATLANTIS']))
        ->assertOk()
        ->assertExactJson(['accepted' => true, 'nationality_required' => true]);

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(['nationality' => 'SG']))
        ->assertOk()
        ->assertExactJson(['accepted' => true, 'nationality_required' => false]);
});

it('does not ask for a nationality when no foreign-only tax is on', function () {
    ['unit' => $unit, 'property' => $property] = kioskStaySetup();
    Tax::factory()->for($property)->create(['foreign_guests_only' => false]);
    Tax::factory()->for($property)->create(['foreign_guests_only' => true, 'enabled' => false]);

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(['nationality' => '']))
        ->assertOk()
        ->assertJsonPath('nationality_required', false);
});

it('refuses a body that is not valid and saves nothing', function (array $bad) {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup();

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody($bad))
        ->assertOk()
        ->assertExactJson(['accepted' => false, 'nationality_required' => false]);

    expect(GuestIdentityDocument::query()->where('guest_id', $guest->id)->exists())->toBeFalse();
})->with([
    'unknown kind' => [['kind' => 'driving_licence']],
    'MyKad that is not 12 digits' => [['number' => '12345']],
    'number too short' => [['kind' => 'passport', 'number' => 'A1']],
    'no name' => [['full_name' => '  ']],
    'number is a list' => [['number' => ['900101141234']]],
]);

it('answers booking_not_found for a stay of another property, like a missing one', function () {
    kioskStaySetup();
    $other = Property::factory()->create();
    ['unit' => $foreign, 'guest' => $foreignGuest] = kioskStayFor($other, 'R-200');

    $foreignAnswer = kioskPost("/stays/{$foreign->id}/identity", kioskMyKadBody());
    $missingAnswer = kioskPost('/stays/999999/identity', kioskMyKadBody());

    $foreignAnswer->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    expect($foreignAnswer->json())->toBe($missingAnswer->json())
        ->and(GuestIdentityDocument::query()->where('guest_id', $foreignGuest->id)->exists())->toBeFalse();
    kioskPost('/stays/abc/identity', kioskMyKadBody())->assertNotFound();
});

it('does not take identity for a stay that is no longer open to check in', function () {
    ['unit' => $unit] = kioskStaySetup(['status' => 'cancelled']);

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody())->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
});

it('does not take identity for a guest that was merged into another guest', function () {
    ['unit' => $unit, 'guest' => $guest, 'property' => $property] = kioskStaySetup();
    $guest->forceFill(['merged_into_id' => Guest::factory()->create(['property_id' => $property->id])->id])->save();

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody())->assertOk()->assertJsonPath('accepted', false);
    expect(GuestIdentityDocument::query()->where('guest_id', $guest->id)->exists())->toBeFalse();
});

it('uses the booker when the stay has no primary guest', function () {
    ['unit' => $unit, 'guest' => $booker] = kioskStaySetup();
    $unit->guests()->delete();

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody())->assertOk()->assertJsonPath('accepted', true);

    expect(GuestIdentityDocument::query()->where('guest_id', $booker->id)->exists())->toBeTrue();
});

it('keeps ID data out of the activity log', function () {
    ['unit' => $unit] = kioskStaySetup(guest: ['full_name' => '']);

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody())->assertOk();

    $log = Activity::query()->get()->toJson();
    expect(Activity::query()->where('event', 'kiosk.identity_received')->exists())->toBeTrue()
        ->and($log)->not->toContain('900101')
        ->and($log)->not->toContain('AISHA')
        ->and($log)->not->toContain('1990-01-01');
});

it('replays the first answer for the same Idempotency-Key and writes once', function () {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup();
    $headers = ['Idempotency-Key' => 'b1f0c9de-7a0e-4a52-9d57-1a2f9d3c1001'];

    $first = kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(), headers: $headers)->assertOk();
    // the same key with a different body still gets the first answer, and the second body is not saved
    $second = kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(['kind' => 'passport', 'number' => 'ZZ998877']), headers: $headers);

    $second->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and(GuestIdentityDocument::query()->where('guest_id', $guest->id)->count())->toBe(1);
});

it('does not replay after 24 hours, and runs the request again', function () {
    ['unit' => $unit, 'guest' => $guest] = kioskStaySetup();
    $headers = ['Idempotency-Key' => 'b1f0c9de-7a0e-4a52-9d57-1a2f9d3c1002'];

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(), headers: $headers)->assertOk();
    $this->travel(25)->hours();
    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(['kind' => 'passport', 'number' => 'ZZ998877']), headers: $headers)
        ->assertOk()->assertHeaderMissing('Idempotent-Replayed');

    expect(GuestIdentityDocument::query()->where('guest_id', $guest->id)->count())->toBe(2);
});

it('does not share an Idempotency-Key between kiosks', function () {
    ['unit' => $unit, 'property' => $property] = kioskStaySetup();
    Kiosk::factory()->for($property)->withToken('est_live_secondkioskaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->create();
    $headers = ['Idempotency-Key' => 'b1f0c9de-7a0e-4a52-9d57-1a2f9d3c1003'];

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(), headers: $headers)->assertOk();
    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(['kind' => 'passport', 'number' => 'ZZ998877']), 'est_live_secondkioskaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $headers)
        ->assertOk()->assertHeaderMissing('Idempotent-Replayed');
});

it('refuses a missing, wrong or wrong-scope token', function () {
    ['unit' => $unit] = kioskStaySetup();

    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(), null)->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody(), 'est_live_wrongwrongwrongwrongwrongwrongwrong0000')->assertUnauthorized();

    PersonalAccessToken::query()->update(['abilities' => ['v1:read']]);
    kioskPost("/stays/{$unit->id}/identity", kioskMyKadBody())->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    expect(GuestIdentityDocument::query()->exists())->toBeFalse();
});

it('does not write to a stay outside the check-in window, and answers like a missing stay', function (array $unit) {
    ['unit' => $stay, 'guest' => $guest] = kioskStaySetup($unit);
    $missing = kioskPost('/stays/999999/identity', kioskMyKadBody());

    $identity = kioskPost("/stays/{$stay->id}/identity", kioskMyKadBody());
    $correction = kioskPost("/stays/{$stay->id}/guest", ['full_name' => 'Someone Else']);

    $identity->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    expect($identity->json())->toBe($missing->json())
        ->and($correction->json())->toBe($missing->json())
        ->and(GuestIdentityDocument::query()->where('guest_id', $guest->id)->exists())->toBeFalse()
        ->and($guest->refresh()->full_name)->toBe('Aisha Rahman');
})->with([
    'arrival not reached' => [['arrival_date' => '2026-10-15', 'departure_date' => '2026-10-17']],
    'stay already over' => [['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']],
]);

it('does not write to any stay while the property has no open business date', function () {
    ['unit' => $stay, 'property' => $property] = kioskStaySetup();
    BusinessDate::query()->where('property_id', $property->id)->delete();

    kioskPost("/stays/{$stay->id}/identity", kioskMyKadBody())->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
});
