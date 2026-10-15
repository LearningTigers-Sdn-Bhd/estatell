<?php

use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\PropertyLicence;
use App\Models\User;
use App\Services\Property\CountryProfiles;
use App\Services\Property\SettingsLock;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    $this->save = fn (array $overrides = []) => $this->actingAs($this->owner)
        ->put(route('onboarding.basics.update', $this->property->code), basicsInput($overrides));
});

test('Malaysia needs no currency or time zone and is seeded with the Malaysian profile', function () {
    ($this->save)(['state_code' => 'Sabah', 'currency' => 'USD', 'time_zone' => 'UTC'])->assertSessionHasNoErrors();

    $property = $this->property->refresh();

    expect($property->country_code)->toBe('MY')
        ->and($property->state_code)->toBe('Sabah')
        ->and($property->settings->currency->getCurrencyCode())->toBe('MYR')
        ->and($property->settings->time_zone)->toBe('Asia/Kuala_Lumpur')
        ->and($property->taxes()->count())->toBe(2);
});

test('outside Malaysia the currency and a time zone of the country are required', function () {
    ($this->save)(['country_code' => 'ID'])->assertSessionHasErrors(['currency', 'time_zone']);
    ($this->save)(['country_code' => 'ID', 'currency' => 'IDR', 'time_zone' => 'Asia/Kuala_Lumpur'])->assertSessionHasErrors(['time_zone']);
    ($this->save)(['country_code' => 'ID', 'currency' => 'KWD', 'time_zone' => 'Asia/Jakarta'])->assertSessionHasErrors(['currency']);
    ($this->save)(['country_code' => 'XX'])->assertSessionHasErrors(['country_code']);
    ($this->save)(['country_code' => null])->assertSessionHasErrors(['country_code']);

    expect($this->property->settings()->exists())->toBeFalse();
});

test('a Malaysian state must come from the list, other countries take free text', function () {
    ($this->save)(['state_code' => 'Atlantis'])->assertSessionHasErrors('state_code');
    ($this->save)(['country_code' => 'ID', 'currency' => 'IDR', 'time_zone' => 'Asia/Jakarta', 'state_code' => 'Bali'])
        ->assertSessionHasNoErrors();

    expect($this->property->refresh()->state_code)->toBe('Bali');
});

test('another country is seeded with the generic presets and its own currency and time zone', function () {
    ($this->save)(['country_code' => 'ID', 'currency' => 'USD', 'time_zone' => 'Asia/Makassar'])->assertSessionHasNoErrors();

    $property = $this->property->refresh();

    expect($property->country_code)->toBe('ID')
        ->and($property->settings->currency->getCurrencyCode())->toBe('USD')
        ->and($property->settings->time_zone)->toBe('Asia/Makassar')
        ->and($property->taxes()->count())->toBe(0)
        ->and($property->transactionCodes()->whereIn('system_key', ['sst_tax', 'tourism_tax'])->count())->toBe(0)
        ->and($property->paymentMethods()->count())->toBe(4)
        ->and($property->termsDocuments()->sole()->versions()->pluck('locale')->map->value->unique()->all())->toBe(['en']);
});

test('business details and licences are saved with the basics', function () {
    ($this->save)([
        'legal_name' => 'Seaview Hospitality Sdn Bhd',
        'registration_number' => '202301012345',
        'tin' => 'C1234567890',
        'licences' => [['id' => null, 'issuer' => ' Dewan Bandaraya Kota Kinabalu ', 'number' => 'DBKK/LP/1', 'expires_on' => '2026-12-31', 'show_on_documents' => false]],
    ])->assertSessionHasNoErrors();

    $property = $this->property->refresh();
    $licence = $property->licences()->sole();

    expect($property->legal_name)->toBe('Seaview Hospitality Sdn Bhd')
        ->and($property->registration_number)->toBe('202301012345')
        ->and($property->tin)->toBe('C1234567890')
        ->and($licence->issuer)->toBe('Dewan Bandaraya Kota Kinabalu')
        ->and($licence->expires_on->toDateString())->toBe('2026-12-31');
});

test('a licence needs an issuer and a number and a date must be valid', function () {
    ($this->save)(['licences' => [['id' => null, 'issuer' => '', 'number' => '', 'expires_on' => '31/12/2026']]])
        ->assertSessionHasErrors(['licences.0.issuer', 'licences.0.number', 'licences.0.expires_on']);
    ($this->save)()->assertSessionHasNoErrors();
    ($this->save)(['licences' => null])->assertSessionHasErrors('licences');
});

test('a licence id from another property is a validation error and nothing is saved', function () {
    $foreign = PropertyLicence::factory()->create();

    ($this->save)(['licences' => [['id' => $foreign->id, 'issuer' => 'MOTAC', 'number' => 'M-1']]])
        ->assertSessionHasErrors('licences.0.id');

    expect($this->property->settings()->exists())->toBeFalse()
        ->and($foreign->refresh()->issuer)->not->toBe('MOTAC');
});

test('saving again keeps the licences that stay and removes the ones that are left out', function () {
    $keep = PropertyLicence::factory()->for($this->property)->create(['issuer' => 'MOTAC', 'number' => 'M-1']);
    PropertyLicence::factory()->for($this->property)->create();

    ($this->save)(['licences' => [['id' => $keep->id, 'issuer' => 'MOTAC', 'number' => 'M-2']]])->assertSessionHasNoErrors();

    expect($this->property->licences()->sole()->only(['id', 'number']))->toBe(['id' => $keep->id, 'number' => 'M-2']);
});

test('the page sends the options, the profiles and the saved values', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.basics.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/basics')
            ->where('basics.countryCode', 'MY')
            ->where('basics.currency', 'MYR')
            ->where('basics.timeZone', 'Asia/Kuala_Lumpur')
            ->where('basics.locked', false)
            ->where('countries.0.value', 'MY')
            ->where('regions.ID.timeZones.0', 'Asia/Jakarta')
            ->where('regions.ID.currency', 'IDR')
            ->where('profiles.MY.fixedRegion', true)
            ->where('profiles.MY.tinLabel', 'TIN (LHDN)')
            ->where('profiles.default.fixedRegion', false)
            ->where('profiles.default.tinLabel', 'Tax ID (TIN)')
            ->where('malaysianStates.0.value', 'Johor')
            ->has('currencies')
            ->where('maxLicences', 20));
});

test('before setup starts the owner can change country and the old presets go', function () {
    ($this->save)()->assertSessionHasNoErrors();
    expect($this->property->refresh()->taxes()->count())->toBe(2);

    ($this->save)(['country_code' => 'ID', 'currency' => 'IDR', 'time_zone' => 'Asia/Jakarta'])->assertSessionHasNoErrors();

    $property = $this->property->refresh();

    expect($property->country_code)->toBe('ID')
        ->and($property->settings->currency->getCurrencyCode())->toBe('IDR')
        ->and($property->taxes()->count())->toBe(0)
        ->and($property->paymentMethods()->count())->toBe(4);
});

test('once another section has started, country currency and time zone stay as saved', function () {
    ($this->save)()->assertSessionHasNoErrors();
    OnboardingSkip::factory()->for($this->property)->create(['step' => 'companies', 'skipped_by' => $this->owner->id]);

    $this->actingAs($this->owner)
        ->get(route('onboarding.basics.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('basics.locked', true));

    ($this->save)(['name' => 'Renamed Lodge', 'country_code' => 'ID', 'currency' => 'IDR', 'time_zone' => 'Asia/Jakarta'])
        ->assertSessionHasNoErrors();

    $property = $this->property->refresh();

    expect($property->name)->toBe('Renamed Lodge')
        ->and($property->country_code)->toBe('MY')
        ->and($property->settings->currency->getCurrencyCode())->toBe('MYR')
        ->and($property->taxes()->count())->toBe(2);
});

test('a live property keeps its country', function () {
    $live = User::factory()->owner()->for(Property::factory())->create();
    $live->property->settings()->create(['currency' => 'MYR', 'time_zone' => 'Asia/Kuala_Lumpur']);

    expect(app(SettingsLock::class)->locksCountry($live->property))->toBeTrue();
});

test('a country whose currency is not offered prefills no currency', function () {
    expect(CountryProfiles::for('KW')->suggestedCurrency())->toBeNull()
        ->and(CountryProfiles::for('ID')->suggestedCurrency())->toBe('IDR');
});
