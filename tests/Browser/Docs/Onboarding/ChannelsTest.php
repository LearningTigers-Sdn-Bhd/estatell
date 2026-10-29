<?php

use App\Models\OtaChannel;
use App\Models\PropertyOtaCredential;
use Database\Seeders\OtaChannelSeeder;

beforeEach(function () {
    $this->seed(OtaChannelSeeder::class);
    $this->startOnboarding();
    $this->saveOnboardingBasics();
});

test('ota lists empty', function () {
    $page = $this->open(route('onboarding.channel-manager.edit', $this->property->code), height: 700)
        ->waitForText('No channels yet')
        ->wait(1);

    $this->shoot($page, 'channels');
});

test('ota lists filled', function () {
    $page = $this->open(route('onboarding.channel-manager.edit', $this->property->code), height: 700)
        ->waitForText('No channels yet')
        ->click('Add channel')
        ->click('[aria-label="Channel of row 1"]')
        ->click('[role="option"]:has-text("Agoda")')
        ->type('[aria-label="Property ID of row 1"]', '123456')
        ->type('[aria-label="Username of row 1"]', 'frontdesk@seaview.my')
        ->type('[aria-label="Password of row 1"]', 'extranet-password')
        ->type('[aria-label="Market manager of row 1"]', 'Aisha Rahman')
        ->wait(1);

    $this->shoot($page, 'channels-filled', badges: ['Channel', 'Property ID', 'Username', 'Password'], badgesFromLeft: 0);
});

test('ota lists saved', function () {
    PropertyOtaCredential::factory()->for($this->property)->create([
        'ota_channel_id' => OtaChannel::where('code', 'agoda')->value('id'),
        'property_code' => '123456',
        'username' => 'frontdesk@seaview.my',
        'password' => 'extranet-password',
        'market_manager_name' => 'Aisha Rahman',
    ]);

    $page = $this->open(route('onboarding.channel-manager.edit', $this->property->code), height: 700)
        ->waitForText('On file')
        ->wait(1);

    $this->shoot($page, 'channels-saved');
});
