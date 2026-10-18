<?php

use App\Enums\CompanyStatus;
use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PortalSetup.php';

beforeEach(function () {
    $this->user = portalUser();
    $this->property = Property::factory()->create();
    [$this->company, $this->link] = portalLinkFor($this->user, $this->property);
    $this->url = fn (?Property $property = null) => route('portal.show', ($property ?? $this->property)->code);
});

test('a company user with an Active link at a Live property opens the dashboard', function () {
    $this->actingAs($this->user)
        ->get(($this->url)())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/show')
            ->where('companyName', 'Borneo Travel')
            ->where('openBalance', '0.00')
            ->where('portalCompany.companyName', 'Borneo Travel')
            ->where('portalCompany.propertyCode', $this->property->code)
            ->where('portalNavigation.1.title', 'Dashboard')
        );
});

test('everyone else gets a 404', function (string $case) {
    $actor = match ($case) {
        'staff' => User::factory()->owner()->for($this->property)->create(),
        'admin' => User::factory()->admin()->create(),
        'partner' => User::factory()->partner(Partner::factory()->create())->create(),
        'company user without a link' => portalUser(),
        default => $this->user,
    };

    match ($case) {
        'pending link' => $this->link->forceFill(['status' => 'pending', 'accepted_at' => null])->save(),
        'revoked link' => $this->link->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save(),
        'property not live' => $this->property->forceFill(['status' => 'onboarding'])->save(),
        'property suspended' => $this->property->forceFill(['status' => 'suspended'])->save(),
        default => null,
    };

    $this->actingAs($actor)->get(($this->url)())->assertNotFound();
})->with(['staff', 'admin', 'partner', 'company user without a link', 'pending link', 'revoked link', 'property not live', 'property suspended']);

test('a link at one property does not open another property', function () {
    $other = Property::factory()->create();

    $this->actingAs($this->user)->get(($this->url)($other))->assertNotFound();
});

test('a guest is sent to sign in', function () {
    $this->get(($this->url)())->assertRedirect(route('login'));
});

test('a Suspended company still opens the dashboard', function () {
    $this->company->forceFill(['status' => CompanyStatus::Suspended])->save();

    $this->actingAs($this->user)
        ->get(($this->url)())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('companySuspended', true));
});

test('an unverified company user waits at the verification notice', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($this->user)->get(($this->url)())->assertRedirect(route('verification.notice'));
});
