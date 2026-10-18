<?php

use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PortalSetup.php';

test('a company user gets the portal navigation and no staff props', function () {
    $user = portalUser();
    $property = Property::factory()->create();
    portalLinkFor($user, $property);

    $this->actingAs($user)
        ->get(route('portal.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('portalNavigation.0.title', 'Properties')
            ->where('property', null)
            ->where('navigation', null)
            ->where('businessDate', null)
            ->where('adminNavigation', null)
            ->where('partnerNavigation', null)
        );
});

test('staff, admin and partner users get no portal props', function (string $kind) {
    $user = match ($kind) {
        'staff' => User::factory()->owner()->create(),
        'admin' => User::factory()->admin()->create(),
        'partner' => User::factory()->partner(Partner::factory()->create())->create(),
    };

    $this->actingAs($user)
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('portalNavigation', null)
            ->where('portalCompany', null)
        );
})->with(['staff', 'admin', 'partner']);

test('staff keep their property props and navigation', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('dashboard', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('property.code', $owner->property->code)
            ->where('portalNavigation', null)
            ->whereNot('navigation', null)
        );
});

test('a company user signs in to the list even with one property', function () {
    $user = portalUser();
    portalLinkFor($user, Property::factory()->create());

    $this->actingAs($user)->get(route('home.redirect'))->assertRedirect(route('portal.index'));
});
