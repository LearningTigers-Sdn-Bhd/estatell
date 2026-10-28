<?php

use App\Actions\Onboarding\RejectGoLive;
use App\Actions\Onboarding\RequestGoLive;
use App\Enums\OnboardingStep;
use App\Models\PlatformSetting;
use App\Models\User;

beforeEach(function () {
    PlatformSetting::query()->whereKey(1)->update(['require_go_live_approval' => true]);
    $this->startOnboarding();
    $this->makeOnboardingReady();
});

test('send for approval', function () {
    $page = $this->open(route('onboarding.go-live.show', $this->property->code), height: 1100)
        ->waitForText('Everything is set')
        ->wait(1);

    $this->shoot($page, 'send-for-approval', badges: ['Send for approval']);
});

test('confirm dialog', function () {
    $page = $this->open(route('onboarding.go-live.show', $this->property->code), height: 700)
        ->waitForText('Everything is set')
        ->click('Send for approval')
        ->waitForText('sign-in details')
        ->wait(1);

    $this->shoot($page, 'send-for-approval-dialog', element: '[role="alertdialog"]');
});

test('waiting for approval', function () {
    $page = $this->open(route('onboarding.go-live.show', $this->property->code), height: 700)
        ->waitForText('Everything is set')
        ->click('Send for approval')
        ->waitForText('sign-in details')
        ->click('[role="alertdialog"] button[type="submit"]')
        ->waitForText('Waiting for approval')
        ->wait(1);

    $this->shoot($page, 'waiting', badges: ['Waiting for approval', 'Withdraw to make changes'], badgesFromLeft: 0, element: '[role="alert"]');
});

test('sent back', function () {
    $owner = auth()->user();
    app(RequestGoLive::class)($owner, $this->property);
    app(RejectGoLive::class)(User::factory()->admin()->create(), $this->property, 'Please check the tax rate and the room prices.', [OnboardingStep::Taxes, OnboardingStep::BasicRate]);

    $page = $this->open(route('onboarding.go-live.show', $this->property->code), height: 1100)
        ->waitForText('Not approved yet')
        ->wait(1);

    $this->shoot($page, 'sent-back', badges: ['Not approved yet', 'Flagged by support'], badgesFromLeft: 0);
});
