<?php

use App\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PortalSetup.php';
require_once __DIR__.'/../Support/PortalBookingSetup.php';

test('outside a property the sidebar has Dashboard and Properties and no switcher', function () {
    $this->actingAs(portalUser())
        ->get(route('portal.properties'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('portalNavigation', 2)
            ->where('portalNavigation.0.title', 'Dashboard')
            ->where('portalNavigation.0.href', '/portal')
            ->where('portalNavigation.0.active', false)
            ->where('portalNavigation.1.title', 'Properties')
            ->where('portalNavigation.1.href', '/portal/properties')
            ->where('portalNavigation.1.active', true)
            ->where('portalSwitcher', null)
        );

    $this->actingAs(portalUser())
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page->where('portalNavigation.0.active', true));
});

test('inside a property the sidebar has Dashboard, Bookings, Statements and Company details and no Book', function () {
    $user = portalUser();
    $property = Property::factory()->create();
    portalLinkFor($user, $property);
    $code = $property->code;

    $this->actingAs($user)
        ->get(route('portal.show', $code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('portalNavigation', 4)
            ->where('portalNavigation.0.title', 'Dashboard')
            ->where('portalNavigation.0.href', "/portal/{$code}")
            ->where('portalNavigation.0.active', true)
            ->where('portalNavigation.1.title', 'Bookings')
            ->where('portalNavigation.1.href', "/portal/{$code}/bookings")
            ->where('portalNavigation.2.title', 'Statements')
            ->where('portalNavigation.3.title', 'Company details')
            ->where('portalNavigation.3.href', "/portal/{$code}/company")
        );
});

test('Bookings stays active on the new booking page', function () {
    $setup = portalBookingSetup();

    $this->actingAs($setup['portalUser'])
        ->get(route('portal.bookings.create', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('portalNavigation.1.title', 'Bookings')
            ->where('portalNavigation.1.active', true)
            ->where('portalNavigation.0.active', false)
        );
});

test('the switcher lists only the user\'s other Active links at Live properties', function () {
    $user = portalUser();
    $current = Property::factory()->create(['name' => 'Current Inn']);
    portalLinkFor($user, $current, 'active', 'Current Co');
    $beta = Property::factory()->create(['name' => 'Beta Lodge']);
    portalLinkFor($user, $beta, 'active', 'Beta Co');
    $alpha = Property::factory()->create(['name' => 'Alpha Villa']);
    portalLinkFor($user, $alpha, 'active', 'Alpha Co');
    portalLinkFor($user, Property::factory()->onboarding()->create(), 'active', 'Not Live Co');
    portalLinkFor($user, Property::factory()->create(), 'pending', 'Pending Co');
    portalLinkFor($user, Property::factory()->create(), 'revoked', 'Revoked Co');
    portalLinkFor(portalUser(), Property::factory()->create(), 'active', 'Stranger Co');

    $this->actingAs($user)
        ->get(route('portal.show', $current->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('portalCompany.propertyName', 'Current Inn')
            ->where('portalCompany.companyName', 'Current Co')
            ->has('portalSwitcher', 2)
            ->where('portalSwitcher.0.propertyName', 'Alpha Villa')
            ->where('portalSwitcher.0.companyName', 'Alpha Co')
            ->where('portalSwitcher.0.propertyCode', $alpha->code)
            ->where('portalSwitcher.1.propertyName', 'Beta Lodge')
        );
});

test('the booking pages moved under bookings and the old book URLs are gone', function () {
    $setup = portalBookingSetup();
    $code = $setup['property']->code;

    expect(route('portal.bookings.create', $setup['property'], false))->toBe("/portal/{$code}/bookings/new")
        ->and(route('portal.bookings.offers', $setup['property'], false))->toBe("/portal/{$code}/bookings/offers")
        ->and(route('portal.bookings.store', $setup['property'], false))->toBe("/portal/{$code}/bookings");

    $this->actingAs($setup['portalUser'])->get("/portal/{$code}/bookings/new")->assertOk();
    $this->actingAs($setup['portalUser'])->get("/portal/{$code}/book")->assertNotFound();
    $this->actingAs($setup['portalUser'])->postJson("/portal/{$code}/book/offers")->assertNotFound();
    $this->actingAs($setup['portalUser'])->post("/portal/{$code}/book")->assertNotFound();
});
