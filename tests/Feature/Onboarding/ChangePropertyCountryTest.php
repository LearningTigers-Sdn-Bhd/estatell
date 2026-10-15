<?php

use App\Actions\Onboarding\SavePropertyBasics;
use App\Actions\Property\ChangePropertyCountry;
use App\Enums\OnboardingStep;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    $input = basicsInput();
    app(SavePropertyBasics::class)($this->owner, $this->property, [
        'type' => $this->property->type,
        'name' => $input['name'],
        'address_line1' => $input['address_line1'],
        'city' => $input['city'],
        'country_code' => 'MY',
    ], ['legal_name' => null, 'registration_number' => null, 'tin' => null], []);
    $this->property->refresh();
});

test('while no other section has started the country changes and no Malaysian preset is left', function () {
    expect(app(ChangePropertyCountry::class)($this->owner, $this->property, 'TH', 'THB', 'Asia/Bangkok'))->toBeTrue();

    $property = $this->property->refresh();

    expect($property->country_code)->toBe('TH')
        ->and($property->settings->currency->getCurrencyCode())->toBe('THB')
        ->and($property->settings->time_zone)->toBe('Asia/Bangkok')
        ->and($property->taxes()->count())->toBe(0)
        ->and($property->transactionCodes()->whereIn('system_key', ['sst_tax', 'tourism_tax'])->exists())->toBeFalse()
        ->and($property->paymentMethods()->with('transactionCode')->get()->pluck('transactionCode.system_key')->sort()->values()->all())
        ->toBe(['bank_transfer_payment', 'card_payment', 'cash_payment', 'direct_bill_payment'])
        ->and($property->termsDocuments()->count())->toBe(1)
        ->and($property->termsDocuments()->sole()->versions()->where('locale', 'ms')->exists())->toBeFalse();
});

test('changing back to Malaysia brings the Malaysian presets and fixed currency and time zone back', function () {
    $change = app(ChangePropertyCountry::class);
    $change($this->owner, $this->property, 'TH', 'THB', 'Asia/Bangkok');
    $change($this->owner, $this->property->refresh(), 'MY', 'THB', 'Asia/Bangkok');

    $property = $this->property->refresh();

    expect($property->country_code)->toBe('MY')
        ->and($property->settings->currency->getCurrencyCode())->toBe('MYR')
        ->and($property->settings->time_zone)->toBe('Asia/Kuala_Lumpur')
        ->and($property->taxes()->count())->toBe(2)
        ->and($property->paymentMethods()->count())->toBe(6)
        ->and($property->termsDocuments()->sole()->versions()->where('locale', 'ms')->exists())->toBeTrue();
});

test('it keeps the identity and room data and logs the change', function () {
    app(ChangePropertyCountry::class)($this->owner, $this->property, 'TH', 'THB', 'Asia/Bangkok');

    $property = $this->property->refresh();

    expect($property->name)->toBe('Seaview Guesthouse')
        ->and($property->roles()->count())->toBe(5)
        ->and(Activity::query()->where('event', 'property.country-changed')->sole()->properties->all())
        ->toMatchArray(['from' => 'MY', 'to' => 'TH']);
});

test('it refuses, without writing, after another section started', function () {
    OnboardingSkip::factory()->for($this->property)->create(['step' => OnboardingStep::Companies, 'skipped_by' => $this->owner->id]);

    expect(app(ChangePropertyCountry::class)($this->owner, $this->property, 'TH', 'THB', 'Asia/Bangkok'))->toBeFalse();

    $property = $this->property->refresh();

    expect($property->country_code)->toBe('MY')
        ->and($property->taxes()->count())->toBe(2)
        ->and($property->settings->currency->getCurrencyCode())->toBe('MYR');
});

test('it refuses after go-live', function () {
    $this->property->forceFill(['went_live_at' => now()])->save();

    expect(app(ChangePropertyCountry::class)($this->owner, $this->property, 'TH', 'THB', 'Asia/Bangkok'))->toBeFalse()
        ->and($this->property->refresh()->country_code)->toBe('MY');
});

test('it refuses a property that has no settings yet', function () {
    $fresh = Property::factory()->onboarding()->create();

    expect(app(ChangePropertyCountry::class)($this->owner, $fresh, 'TH'))->toBeFalse();
});

test('saving the basics with a changed country after setup started returns false', function () {
    OnboardingSkip::factory()->for($this->property)->create(['step' => OnboardingStep::Companies, 'skipped_by' => $this->owner->id]);

    $saved = app(SavePropertyBasics::class)($this->owner, $this->property, [
        'type' => $this->property->type,
        'name' => 'Renamed',
        'address_line1' => 'x',
        'city' => 'y',
        'country_code' => 'TH',
        'currency' => 'THB',
        'time_zone' => 'Asia/Bangkok',
    ], ['legal_name' => null, 'registration_number' => null, 'tin' => null], []);

    expect($saved)->toBeFalse()
        ->and($this->property->refresh()->name)->toBe('Seaview Guesthouse');
});
