<?php

use App\Actions\CityLedger\SetCompanyPortalLogin;
use App\Models\Company;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = $this->owner->property;
    $this->company = Company::factory()->for($this->property)->create(['name' => 'Borneo Travel']);
    app(SetCompanyPortalLogin::class)($this->owner, $this->company, 'booking@borneotravel.my');
    $this->companyUser = User::query()->where('email', 'booking@borneotravel.my')->sole();
});

test('a company user signs in with the temp password and lands on the portal page', function () {
    $this->post(route('login.store'), ['email' => $this->companyUser->email, 'password' => $this->companyUser->temporary_password])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($this->companyUser);

    $this->actingAs($this->companyUser->refresh()->forceFill(['must_change_password' => false]))
        ->get(route('home.redirect'))
        ->assertRedirect(route('portal.index'));

    $this->get(route('portal.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('portal/index'));
});

test('a company user with a temp password is sent to set their own password first', function () {
    $this->actingAs($this->companyUser)
        ->get(route('portal.index'))
        ->assertRedirect(route('account.set-password.edit'));
});

test('an expired temp password for a company user says who to ask', function () {
    $this->companyUser->forceFill(['temporary_password_expires_at' => now()->subMinute()])->save();

    $this->post(route('login.store'), ['email' => $this->companyUser->email, 'password' => $this->companyUser->temporary_password])
        ->assertSessionHasErrors(['email' => 'This temporary password has expired. Ask the property that gave you this login for a new one.']);

    $this->assertGuest();
});

test('a company user gets 404 on property pages and the staff dashboard route', function () {
    $this->companyUser->forceFill(['must_change_password' => false, 'temporary_password' => null, 'temporary_password_expires_at' => null])->save();

    $this->actingAs($this->companyUser)
        ->get(route('dashboard', $this->property->code))
        ->assertNotFound();

    $this->actingAs($this->companyUser)
        ->get("/p/{$this->property->code}/finance/city-ledger/companies")
        ->assertNotFound();
});

test('a company user can open the account pages without a property', function () {
    $this->companyUser->forceFill(['must_change_password' => false, 'temporary_password' => null, 'temporary_password_expires_at' => null])->save();

    $this->actingAs($this->companyUser)->get(route('account.profile.edit'))->assertOk();
});

test('the portal page needs a signed-in, verified user', function () {
    $this->get(route('portal.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->companyUser()->unverified()->create())
        ->get(route('portal.index'))
        ->assertRedirect();
});
