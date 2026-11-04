<?php

use App\Actions\Registration\SeedRegistrationCardFields;
use App\Actions\Registration\SeedRegistrationTerms;
use App\Enums\Locale;
use App\Enums\PropertyType;
use App\Enums\RegistrationCardStatus;
use App\Models\ApiToken;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\RegistrationCard;
use App\Models\ReservationUnit;
use App\Services\Registration\RegistrationTermsResolver;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/KioskSetup.php';

const KIOSK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/** @return array{property: Property, kiosk: ApiToken, unit: ReservationUnit, guest: Guest, version: int} */
function kioskCardSetup(): array
{
    $setup = kioskStaySetup();
    app(SeedRegistrationCardFields::class)($setup['property']);
    app(SeedRegistrationTerms::class)($setup['property'], PropertyType::Hotel);
    $terms = app(RegistrationTermsResolver::class)->resolve($setup['property'], null, null, Locale::English);

    return [...$setup, 'version' => $terms->terms->version];
}

it('gives the terms text and version in the language of Accept-Language', function () {
    ['unit' => $unit, 'version' => $version, 'property' => $property] = kioskCardSetup();

    $en = kioskRead("/stays/{$unit->id}/registration-card", headers: ['Accept-Language' => 'en'])->assertOk();
    $ms = kioskRead("/stays/{$unit->id}/registration-card", headers: ['Accept-Language' => 'ms'])->assertOk();

    expect($en->json('terms_version'))->toBe($version)
        ->and($en->json('terms_text'))->toBeString()->not->toBe('')->toContain($property->name)->not->toContain('{{')
        ->and($ms->json('terms_text'))->not->toBe($en->json('terms_text'));
});

it('falls back to a language it has when zh is asked for', function () {
    ['unit' => $unit] = kioskCardSetup();

    kioskRead("/stays/{$unit->id}/registration-card", headers: ['Accept-Language' => 'zh'])->assertOk()->assertJsonStructure(['terms_version', 'terms_text']);
});

it('signs the primary guest card with a PNG, stores the signature and ticks every item', function () {
    ['unit' => $unit, 'guest' => $guest, 'version' => $version] = kioskCardSetup();
    $document = GuestIdentityDocument::factory()->create(['guest_id' => $guest->id]);

    kioskPost("/stays/{$unit->id}/registration-card/sign", ['terms_version' => $version, 'signature_png' => KIOSK_PNG], headers: ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertExactJson(['signed' => true]);

    $card = RegistrationCard::query()->sole()->refresh();
    expect($card->status)->toBe(RegistrationCardStatus::Signed)
        ->and($card->signer_name)->toBe('Aisha Rahman')
        ->and($card->identity_document_id)->toBe($document->id)
        ->and($card->getFirstMedia(RegistrationCard::SIGNATURE))->not->toBeNull()
        ->and($card->snapshot['terms']['items'])->each(fn ($item) => $item->ticked_at->not->toBeNull())
        ->and(Activity::query()->where('event', 'registration_card.signed')->sole()->causer_id)->toBeNull();
});

it('accepts a PNG data URL as well', function () {
    ['unit' => $unit, 'version' => $version] = kioskCardSetup();

    kioskPost("/stays/{$unit->id}/registration-card/sign", ['terms_version' => $version, 'signature_png' => 'data:image/png;base64,'.KIOSK_PNG])
        ->assertOk()->assertExactJson(['signed' => true]);
});

it('answers signed again for a card that is already signed and keeps one card', function () {
    ['unit' => $unit, 'version' => $version] = kioskCardSetup();
    $body = ['terms_version' => $version, 'signature_png' => KIOSK_PNG];

    kioskPost("/stays/{$unit->id}/registration-card/sign", $body)->assertExactJson(['signed' => true]);
    kioskPost("/stays/{$unit->id}/registration-card/sign", $body)->assertExactJson(['signed' => true]);

    expect(RegistrationCard::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'registration_card.signed')->count())->toBe(1);
});

it('replays the first answer for the same Idempotency-Key and signs once', function () {
    ['unit' => $unit, 'version' => $version] = kioskCardSetup();
    $headers = ['Idempotency-Key' => 'b1f0c9de-7a0e-4a52-9d57-1a2f9d3c2001'];
    $body = ['terms_version' => $version, 'signature_png' => KIOSK_PNG];

    kioskPost("/stays/{$unit->id}/registration-card/sign", $body, headers: $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    kioskPost("/stays/{$unit->id}/registration-card/sign", $body, headers: $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertExactJson(['signed' => true]);

    expect(Activity::query()->where('event', 'registration_card.signed')->count())->toBe(1);
});

it('does not sign for another terms version', function () {
    ['unit' => $unit, 'version' => $version] = kioskCardSetup();

    kioskPost("/stays/{$unit->id}/registration-card/sign", ['terms_version' => $version + 1, 'signature_png' => KIOSK_PNG])
        ->assertOk()->assertExactJson(['signed' => false]);

    expect(RegistrationCard::query()->sole()->status)->toBe(RegistrationCardStatus::Draft);
});

it('does not sign with a signature that is not a PNG or is too big', function (string $signature) {
    ['unit' => $unit, 'version' => $version] = kioskCardSetup();

    kioskPost("/stays/{$unit->id}/registration-card/sign", ['terms_version' => $version, 'signature_png' => $signature])
        ->assertOk()->assertExactJson(['signed' => false]);

    expect(RegistrationCard::query()->where('status', RegistrationCardStatus::Signed)->exists())->toBeFalse();
})->with([
    'text' => fn () => base64_encode('not an image'),
    'html data url' => 'data:text/html;base64,PGI+',
    'jpeg bytes' => fn () => base64_encode("\xFF\xD8\xFF\xE0".str_repeat('a', 100)),
    'over 1 MB' => fn () => base64_encode("\x89PNG\r\n\x1a\n".str_repeat('a', 1_100_000)),
]);

it('answers signed false for a body that is not valid, without echoing it', function () {
    ['unit' => $unit] = kioskCardSetup();

    kioskPost("/stays/{$unit->id}/registration-card/sign", ['terms_version' => 'x', 'signature_png' => KIOSK_PNG])->assertOk()->assertExactJson(['signed' => false]);
    kioskPost("/stays/{$unit->id}/registration-card/sign", [])->assertOk()->assertExactJson(['signed' => false]);
});

it('answers booking_not_found for a stay of another property, a missing stay and a stay outside the window', function () {
    ['unit' => $unit, 'version' => $version] = kioskCardSetup();
    $other = kioskStayFor(Property::factory()->create(), 'R-900');
    $early = kioskStayFor($unit->property, 'R-901', ['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22']);

    foreach ([$other['unit']->id, 999999, $early['unit']->id] as $id) {
        kioskRead("/stays/{$id}/registration-card")->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
        kioskPost("/stays/{$id}/registration-card/sign", ['terms_version' => $version, 'signature_png' => KIOSK_PNG])->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    }

    expect(RegistrationCard::query()->count())->toBe(0);
});

it('refuses a missing or wrong-scope token', function () {
    ['unit' => $unit, 'kiosk' => $kiosk] = kioskCardSetup();
    $kiosk->forceFill(['scopes' => 'v1:read'])->save();

    kioskRead("/stays/{$unit->id}/registration-card")->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    kioskRead("/stays/{$unit->id}/registration-card", token: null)->assertUnauthorized();
    kioskPost("/stays/{$unit->id}/registration-card/sign", [], token: null)->assertUnauthorized();
});

it('answers a stay that has no published terms with server_error and signs nothing', function () {
    ['unit' => $unit] = kioskStaySetup();

    kioskRead("/stays/{$unit->id}/registration-card")->assertStatus(409)->assertJsonPath('error.code', 'server_error');
    kioskPost("/stays/{$unit->id}/registration-card/sign", ['terms_version' => 1, 'signature_png' => KIOSK_PNG])->assertOk()->assertExactJson(['signed' => false]);
});
