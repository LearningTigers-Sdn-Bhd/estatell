<?php

use App\Actions\Onboarding\SavePropertyBasics;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\RoomType;

beforeEach(function () {
    $this->startOnboarding();
});

function saveBasics(Property $property): void
{
    app(SavePropertyBasics::class)(auth()->user(), $property, [
        'name' => 'Seaview Lodge',
        'type' => PropertyType::Hotel->value,
        'address_line1' => '12 Jalan Pantai',
        'city' => 'Kota Kinabalu',
        'country_code' => 'MY',
    ], ['legal_name' => null, 'registration_number' => null, 'tin' => null], []);
}

test('basics form', function () {
    $page = $this->open(route('onboarding.basics.edit', $this->property->code), height: 1100)
        ->waitForText('Property basics')
        ->click('#type')
        ->click('Hotel')
        ->type('#address_line1', '12 Jalan Pantai')
        ->type('#city', 'Kota Kinabalu')
        ->type('#postal_code', '88000');

    $this->shoot($page, 'basics');
});

test('basics for another country', function () {
    $page = $this->open(route('onboarding.basics.edit', $this->property->code), height: 1100)
        ->waitForText('Property basics')
        ->click('#country_code')
        ->type('[placeholder="Search countries"]', 'Indonesia')
        ->click('[role="option"]')
        ->waitForText("Malaysian presets don't apply");

    $this->shoot($page, 'basics-other-country');
});

test('basics after setup started', function () {
    saveBasics($this->property);
    RoomType::factory()->create(['property_id' => $this->property->id]);

    $page = $this->open(route('onboarding.basics.edit', $this->property->code), height: 900)
        ->waitForText('Change country');

    $this->shoot($page, 'basics-locked-country', element: '[aria-labelledby="property-details"]');

    $page->click('Change country')
        ->click('#country_code')
        ->type('[placeholder="Search countries"]', 'Indonesia')
        ->click('[role="option"]')
        ->click('Save & continue')
        ->waitForText('Change country to Indonesia?');

    $this->shoot($page, 'change-country-dialog', element: '[role="alertdialog"]');
});

test('check-in essentials', function () {
    saveBasics($this->property);

    $this->post(route('onboarding.basics.update', $this->property->code));

    $page = $this->open(route('onboarding.check-in-essentials.edit', $this->property->code), height: 1000)
        ->waitForText('Check-in from');

    $this->shoot($page, 'check-in-essentials');
});

test('team', function () {
    saveBasics($this->property);

    $page = $this->open(route('onboarding.team.edit', $this->property->code), height: 900)
        ->waitForText('Add person')
        ->type('input[placeholder="e.g. Aina Rahman"]', 'Aina Rahman')
        ->type('input[placeholder="e.g. aina@seaview.my"]', 'aina@seaview.my');

    $this->shoot($page, 'team');
});
