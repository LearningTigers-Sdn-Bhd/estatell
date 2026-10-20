<?php

use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the admin onboarding tab lists the step, percentage and last activity of each onboarding property', function () {
    $active = Property::factory()->onboarding()->create(['onboarding_last_activity_at' => '2026-10-12 09:30:00']);
    $quiet = Property::factory()->onboarding()->create(['onboarding_last_activity_at' => null, 'onboarding_started_at' => '2026-10-01 08:00:00']);
    Property::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.properties.index', ['filter' => ['status' => 'onboarding']]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 2)
            ->has("onboarding.{$active->code}", fn (Assert $row) => $row
                ->where('currentStep', 'Basics')
                ->where('percent', 0)
                ->where('lastActivity', '12 Oct 2026, 09:30'))
            ->where("onboarding.{$quiet->code}.lastActivity", '1 Oct 2026, 08:00'));
});

test('the admin list sends no onboarding rows on the other tabs', function () {
    Property::factory()->onboarding()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.properties.index'))
        ->assertInertia(fn (Assert $page) => $page->where('onboarding', []));
});

test('the partner onboarding tab lists only this partner\'s onboarding properties', function () {
    $partner = Partner::factory()->create();
    $mine = Property::factory()->onboarding()->create(['partner_id' => $partner->id]);
    Property::factory()->create(['partner_id' => $partner->id]);
    Property::factory()->onboarding()->create(['partner_id' => Partner::factory()->create()->id]);
    Property::factory()->onboarding()->create();

    $this->actingAs(User::factory()->partner($partner)->create())
        ->get(route('partner.properties.index', ['status' => 'onboarding']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboardingTab', true)
            ->has('properties.data', 1)
            ->where('properties.data.0.code', $mine->code)
            ->has("onboarding.{$mine->code}.currentStep")
            ->has("onboarding.{$mine->code}.percent"));

    $this->get(route('partner.properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboardingTab', false)
            ->has('properties.data', 2)
            ->where('onboarding', []));
});
