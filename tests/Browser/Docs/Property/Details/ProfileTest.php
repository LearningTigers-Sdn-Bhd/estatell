<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('profile tab', function () {
    $page = $this->open(route('settings.profile', $this->property->code), height: 1100)
        ->waitForText('Save profile');

    $this->shoot($page, 'profile', badges: ['Logo', 'Property name', 'Address line 1', 'Phone (optional)']);
});

test('business licence tab', function () {
    $page = $this->open(route('settings.business', $this->property->code), height: 1000)
        ->waitForText('Save business details')
        ->click('Add licence')
        ->type('[aria-label="Issued by, licence 1"]', 'Dewan Bandaraya Kota Kinabalu')
        ->type('[aria-label="Licence number, licence 1"]', 'LIC-2026-00123');

    $this->shoot($page, 'business-licence', badges: ['Registered business name (optional)', 'Licences', 'Add licence']);
});
