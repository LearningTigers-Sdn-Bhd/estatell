<?php

use App\Actions\Onboarding\ApproveGoLive;
use App\Actions\Onboarding\RequestGoLive;
use App\Actions\Onboarding\SaveOnboardingTeam;
use App\Actions\Onboarding\SkipOnboardingStep;
use App\Enums\DefaultRoleEnum;
use App\Enums\OnboardingStep;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    PlatformSetting::query()->whereKey(1)->update(['require_go_live_approval' => true]);
    $this->startOnboarding();
    $this->makeOnboardingReady();

    $owner = auth()->user();
    $roleId = Role::query()
        ->where('property_id', $this->property->id)
        ->where('default_role', DefaultRoleEnum::FrontDesk)
        ->value('id');
    app(SaveOnboardingTeam::class)($this->property, $owner, [
        ['name' => 'Aina Rahman', 'email' => 'aina@seaviewlodge.my', 'role_id' => $roleId],
        ['name' => 'Daniel Lim', 'email' => 'daniel@seaviewlodge.my', 'role_id' => $roleId],
        ['name' => 'Siti Nur', 'email' => 'siti@seaviewlodge.my', 'role_id' => $roleId],
    ]);
    app(SkipOnboardingStep::class)($this->property, OnboardingStep::ChargeItems, $owner);

    app(RequestGoLive::class)($owner, $this->property);
    app(ApproveGoLive::class)(User::factory()->admin()->create(), $this->property);
});

test('youre live card', function () {
    $page = $this->open(route('dashboard', ['property' => $this->property->code, 'tab' => 'today']), height: 900)
        ->waitForText("You're live!")
        ->wait(1);

    $this->shoot($page, 'youre-live', badges: ['Share sign-in details', 'Copy messages', 'Export'], badgesFromLeft: 0, element: '[data-slot="card"]:has-text("Share sign-in details")');
});

test('recommended next card', function () {
    $page = $this->open(route('dashboard', ['property' => $this->property->code, 'tab' => 'today']), height: 900)
        ->waitForText('Recommended next')
        ->wait(1);

    $this->shoot($page, 'recommended-next', element: '[data-slot="card"]:has([role="progressbar"])');
});

test('recommended next sheet', function () {
    $page = $this->open(route('dashboard', ['property' => $this->property->code, 'tab' => 'today']), height: 640)
        ->waitForText('Recommended next')
        ->click('See all steps')
        ->waitForText('done')
        ->wait(1);

    $this->shoot($page, 'recommended-next-all', element: '[role="dialog"]');
});
