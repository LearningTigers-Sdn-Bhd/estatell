<?php

use App\Actions\Registration\CreateTermsDocument;
use App\Actions\Registration\PublishRegistrationTerms;
use App\Actions\Registration\SeedRegistrationTerms;
use App\Enums\Locale;
use App\Enums\PropertyType;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Registration\RegistrationTermsResolver;

/** @return array{0: Property, 1: User} */
function resolverProperty(Locale $default = Locale::English): array
{
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create(['default_locale' => $default]);
    app(SeedRegistrationTerms::class)($owner->property, PropertyType::Hotel);

    return [$owner->property->refresh(), $owner];
}

test('the property default document and default language are used without a room type or guest', function () {
    [$property] = resolverProperty();

    $resolved = app(RegistrationTermsResolver::class)->resolve($property);

    expect($resolved->document->is_default)->toBeTrue()
        ->and($resolved->locale)->toBe(Locale::English)
        ->and($resolved->locales)->toBe([Locale::English, Locale::Malay])
        ->and($resolved->terms->locale)->toBe(Locale::English)
        ->and($resolved->terms->version)->toBe(1);
});

test('the guest\'s preferred language wins over the property default, and an explicit choice wins over both', function () {
    [$property] = resolverProperty();
    $guest = Guest::factory()->for($property)->create(['preferred_locale' => Locale::Malay]);
    $resolver = app(RegistrationTermsResolver::class);

    expect($resolver->resolve($property, null, $guest)->locale)->toBe(Locale::Malay)
        ->and($resolver->resolve($property, null, $guest, Locale::English)->locale)->toBe(Locale::English);
});

test('the latest published version is returned', function () {
    [$property, $owner] = resolverProperty();
    $document = $property->termsDocuments()->sole();
    app(PublishRegistrationTerms::class)($document, Locale::English, $owner, 'Second text');
    app(PublishRegistrationTerms::class)($document, Locale::English, $owner, 'Third text');

    $terms = app(RegistrationTermsResolver::class)->resolve($property)->terms;

    expect($terms->version)->toBe(3)->and($terms->body)->toBe('Third text');
});

test('a room type document replaces the default one', function () {
    [$property, $owner] = resolverProperty();
    $dorm = app(CreateTermsDocument::class)($property, $owner, 'Dorm');
    $roomType = RoomType::factory()->for($property)->create(['terms_document_id' => $dorm->id]);

    $resolved = app(RegistrationTermsResolver::class)->resolve($property, $roomType);

    expect($resolved->document->is($dorm))->toBeTrue();
});

test('a room type document without the guest\'s language falls back to the default language of that document', function () {
    [$property, $owner] = resolverProperty();
    $dorm = app(CreateTermsDocument::class)($property, $owner, 'Dorm');
    $dorm->versions()->forLocale(Locale::Malay)->delete();
    $roomType = RoomType::factory()->for($property)->create(['terms_document_id' => $dorm->id]);
    $guest = Guest::factory()->for($property)->create(['preferred_locale' => Locale::Malay]);

    $resolved = app(RegistrationTermsResolver::class)->resolve($property, $roomType, $guest, Locale::Malay);

    expect($resolved->document->is($dorm))->toBeTrue()
        ->and($resolved->locales)->toBe([Locale::English])
        ->and($resolved->locale)->toBe(Locale::English);
});

test('when neither the guest nor the property language exists the first available language is used', function () {
    [$property] = resolverProperty(Locale::Malay);
    $property->termsDocuments()->sole()->versions()->forLocale(Locale::Malay)->delete();

    expect(app(RegistrationTermsResolver::class)->resolve($property)->locale)->toBe(Locale::English);
});

test('a room type without a document, or with an empty one, uses the property default', function () {
    [$property, $owner] = resolverProperty();
    $empty = app(CreateTermsDocument::class)($property, $owner, 'Empty');
    $empty->versions()->delete();
    $plain = RoomType::factory()->for($property)->create();
    $withEmpty = RoomType::factory()->for($property)->create(['terms_document_id' => $empty->id]);
    $resolver = app(RegistrationTermsResolver::class);

    expect($resolver->resolve($property, $plain)->document->is_default)->toBeTrue()
        ->and($resolver->resolve($property, $withEmpty)->document->is_default)->toBeTrue();
});

test('another property\'s document is never used', function () {
    [$property] = resolverProperty();
    [$other] = resolverProperty();
    $foreign = $other->termsDocuments()->sole();
    $roomType = RoomType::factory()->for($property)->create();
    $roomType->forceFill(['terms_document_id' => $foreign->id])->save();

    $resolved = app(RegistrationTermsResolver::class)->resolve($property, $roomType);

    expect($resolved->document->property_id)->toBe($property->id);
});

test('a property without published terms resolves to nothing', function () {
    $property = Property::factory()->create();

    expect(app(RegistrationTermsResolver::class)->resolve($property))->toBeNull();
});
