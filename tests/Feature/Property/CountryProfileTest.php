<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\Locale;
use App\Enums\PaymentMethodType;
use App\Enums\PropertyType;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Services\Property\CountryProfiles;
use App\Services\Property\GenericProfile;
use App\Services\Property\MalaysiaProfile;

test('Malaysia has its own profile and every other country gets the generic one', function () {
    expect(CountryProfiles::for('MY'))->toBeInstanceOf(MalaysiaProfile::class)
        ->and(CountryProfiles::for('my'))->toBeInstanceOf(MalaysiaProfile::class)
        ->and(CountryProfiles::for('TH'))->toBeInstanceOf(GenericProfile::class);
});

test('the generic profile prefills currency and time zone from the country', function (string $country, string $currency, string $timeZone) {
    $profile = CountryProfiles::for($country);

    expect($profile->currency())->toBe($currency)
        ->and($profile->timeZone())->toBe($timeZone);
})->with([
    ['TH', 'THB', 'Asia/Bangkok'],
    ['SG', 'SGD', 'Asia/Singapore'],
    ['ID', 'IDR', 'Asia/Jakarta'],
]);

test('the generic profile falls back when a country has no currency or time zone', function () {
    $profile = CountryProfiles::for('AQ');

    expect($profile->currency())->toBe('USD')
        ->and(CountryProfiles::for('ZZ')->timeZone())->toBe('UTC');
});

test('a property outside Malaysia receives the generic defaults', function () {
    $property = Property::factory()->create(['type' => PropertyType::Hostel, 'country_code' => 'TH']);

    app(SeedPropertyDefaults::class)($property, PropertyType::Hostel, 'TH');

    $settings = $property->settings;
    $codes = $property->transactionCodes()->pluck('system_key')->all();

    expect($settings->currency->getCurrencyCode())->toBe('THB')
        ->and($settings->time_zone)->toBe('Asia/Bangkok')
        ->and($settings->default_locale)->toBe(Locale::English)
        ->and($codes)->toHaveCount(count(SystemTransactionCode::cases()) - 2)
        ->and($codes)->not->toContain(SystemTransactionCode::SstTax->value)
        ->and($codes)->not->toContain(SystemTransactionCode::TourismTax->value)
        ->and($property->taxes()->count())->toBe(0)
        ->and($property->paymentMethods()->orderBy('position')->pluck('type')->all())->toBe([
            PaymentMethodType::Cash,
            PaymentMethodType::Card,
            PaymentMethodType::BankTransfer,
            PaymentMethodType::DirectBill,
        ])
        ->and($property->paymentMethods()->where('active', true)->count())->toBe(3)
        ->and($property->roles()->count())->toBe(5)
        ->and($property->chargeItems()->count())->toBe(4)
        ->and($property->ageGroups()->count())->toBe(2)
        ->and($property->mealPeriods()->count())->toBe(3)
        ->and($property->ratePlans()->count())->toBe(1);
});

test('terms outside Malaysia are seeded in English only', function () {
    $property = Property::factory()->create(['type' => PropertyType::Hotel, 'country_code' => 'TH']);

    app(SeedPropertyDefaults::class)($property, PropertyType::Hotel, 'TH');

    $document = $property->termsDocuments()->where('is_default', true)->firstOrFail();

    expect($document->versions()->get()->pluck('locale')->unique()->all())->toBe([Locale::English]);
});

test('seeding the generic defaults twice creates nothing new', function () {
    $property = Property::factory()->create(['type' => PropertyType::Hotel, 'country_code' => 'TH']);
    $seed = app(SeedPropertyDefaults::class);

    $seed($property, PropertyType::Hotel, 'TH');
    $seed($property, PropertyType::Hotel, 'TH');

    expect($property->transactionCodes()->count())->toBe(count(SystemTransactionCode::cases()) - 2)
        ->and($property->paymentMethods()->count())->toBe(4)
        ->and($property->settings()->count())->toBe(1);
});

test('a property outside Malaysia gets terms without the Malaysian items', function (PropertyType $type) {
    $property = Property::factory()->create(['type' => $type, 'country_code' => 'TH']);

    app(SeedPropertyDefaults::class)($property, $type, 'TH');

    $body = $property->termsDocuments()->where('is_default', true)->firstOrFail()->versions()->firstOrFail()->body;

    expect($body)->not->toContain('Tourism tax')
        ->and($body)->not->toContain('Personal Data Protection Act')
        ->and($body)->toContain('We collect and use your personal data to manage your stay and meet legal duties.');
})->with(PropertyType::cases());

test('a Malaysian property keeps the Malaysian items in its terms', function () {
    $property = Property::factory()->create(['type' => PropertyType::Hotel, 'country_code' => 'MY']);

    app(SeedPropertyDefaults::class)($property, PropertyType::Hotel, 'MY');

    $body = $property->termsDocuments()->where('is_default', true)->firstOrFail()->versions()->where('locale', Locale::English)->firstOrFail()->body;

    expect($body)->toContain('Tourism tax of')
        ->and($body)->toContain('Personal Data Protection Act 2010');
});

test('the business number labels follow the country', function () {
    expect(CountryProfiles::for('MY')->registrationNumberLabel())->toBe('SSM registration no.')
        ->and(CountryProfiles::for('MY')->tinLabel())->toBe('TIN (LHDN)')
        ->and(CountryProfiles::for('TH')->registrationNumberLabel())->toBe('Business registration no.')
        ->and(CountryProfiles::for('TH')->tinLabel())->toBe('Tax ID (TIN)');
});
