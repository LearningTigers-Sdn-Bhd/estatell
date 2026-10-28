<?php

use App\Actions\Onboarding\SkipOnboardingStep;
use App\Enums\OnboardingStep;

beforeEach(function () {
    $this->startOnboarding();
});

test('review with required sections left', function () {
    [$standard] = $this->saveOnboardingPriceableRoomTypes();
    $this->saveOnboardingRooms($standard);
    app(SkipOnboardingStep::class)($this->property, OnboardingStep::ChannelManager, auth()->user());

    $page = $this->open(route('onboarding.go-live.show', $this->property->code), height: 1100)
        ->waitForText('Almost there')
        ->wait(1);

    $this->shoot($page, 'review', badges: [
        'Almost there. Finish 2 required sections to go live.',
        'Seaview Lodge · Malaysia · MYR',
        '5 rooms',
        'Skipped.',
        'Go live',
    ], badgesFromLeft: 0);
});

test('review warning', function () {
    $this->saveOnboardingRoomType();

    $page = $this->open(route('onboarding.go-live.show', $this->property->code), height: 500)
        ->waitForText('No tax is applied to rooms')
        ->wait(1);

    $this->shoot($page, 'review-warning', element: 'form > div:first-child');
});

test('review ready', function () {
    $this->makeOnboardingReady();

    $page = $this->open(route('onboarding.go-live.show', $this->property->code), height: 1100)
        ->waitForText('Everything is set')
        ->wait(1);

    $this->shoot($page, 'review-ready');
});
