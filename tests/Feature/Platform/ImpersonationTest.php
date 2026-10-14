<?php

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->property = Property::factory()->create(['code' => 'ES10142']);
    $this->owner = User::factory()->owner()->create(['property_id' => $this->property->id]);
    $this->admin = User::factory()->admin()->create();
});

function viewAsInput(User $staff, array $overrides = []): array
{
    return ['user' => $staff->id, 'reason' => 'support', ...$overrides];
}

test('an admin opening a staff URL is redirected to the view-as page with the requested path', function () {
    $this->actingAs($this->admin)
        ->get('/p/ES10142/reservations?status=open')
        ->assertRedirect(route('view-as.show', ['property' => 'ES10142', 'to' => '/p/ES10142/reservations?status=open']));
});

test('a partner is redirected for their own property and gets a 404 for another partner\'s', function () {
    $partnerUser = User::factory()->partner()->create();
    $mine = Property::factory()->create(['partner_id' => $partnerUser->partner_id]);
    $theirs = Property::factory()->create(['partner_id' => Partner::factory()->create()->id]);

    $this->actingAs($partnerUser)
        ->get(route('dashboard', $mine->code))
        ->assertRedirect(route('view-as.show', ['property' => $mine->code, 'to' => "/p/{$mine->code}/dashboard"]));

    $this->get(route('dashboard', $theirs->code))->assertNotFound();
    $this->get(route('view-as.show', $theirs->code))->assertNotFound();
    $this->get(route('view-as.show', $this->property->code))->assertNotFound();
});

test('staff of another property get a 404 on the view-as page and on staff URLs', function () {
    $other = User::factory()->create();

    $this->actingAs($other)->get('/p/ES10142/dashboard')->assertNotFound();
    $this->get(route('view-as.show', 'ES10142'))->assertNotFound();
});

test('the view-as page lists active staff with the owner pre-selected and falls back on an unknown reason', function () {
    $manager = User::factory()->create(['property_id' => $this->property->id, 'name' => 'Zed']);
    User::factory()->deactivated()->create(['property_id' => $this->property->id]);

    $this->actingAs($this->admin)
        ->get(route('view-as.show', ['property' => 'ES10142', 'to' => '/p/ES10142/reservations', 'reason' => 'nonsense']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('view-as/show')
            ->has('staff', 2)
            ->where('staff.0.id', $this->owner->id)
            ->where('staff.0.isOwner', true)
            ->where('staff.1.id', $manager->id)
            ->where('defaultUserId', $this->owner->id)
            ->where('reason', 'onboarding_help')
            ->where('to', '/p/ES10142/reservations')
            ->where('consent', true)
        );

    $this->get(route('view-as.show', 'ES10142'))
        ->assertInertia(fn (Assert $page) => $page->where('reason', 'support')->where('to', null));
});

test('starting redirects to the path and shares the impersonation prop', function () {
    $this->actingAs($this->admin)
        ->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner, ['to' => '/p/ES10142/settings/profile', 'note' => 'Call with owner']))
        ->assertRedirect('/p/ES10142/settings/profile');

    expect(session('impersonation'))->toMatchArray([
        'support_user_id' => $this->admin->id,
        'user_id' => $this->owner->id,
        'property_id' => $this->property->id,
        'reason' => 'support',
        'note' => 'Call with owner',
        'return_url' => route('admin.properties.show', 'ES10142', absolute: false),
    ]);

    $this->get(route('dashboard', 'ES10142'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.supportUserName', $this->admin->name)
            ->where('impersonation.partnerName', null)
            ->where('impersonation.userName', $this->owner->name)
            ->where('impersonation.userRole', 'Owner')
            ->where('impersonation.propertyCode', 'ES10142')
            ->where('impersonation.minutesLeft', 30)
            ->where('impersonation.stopUrl', '/impersonation/stop')
        );
});

test('a partner starts a session with their name on the banner', function () {
    $partnerUser = User::factory()->partner()->create();
    $property = Property::factory()->create(['partner_id' => $partnerUser->partner_id]);
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);

    $this->actingAs($partnerUser)
        ->post(route('view-as.store', $property->code), viewAsInput($owner))
        ->assertRedirect(route('dashboard', $property->code, absolute: false));

    $this->get(route('dashboard', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.partnerName', $partnerUser->partner->name)
            ->where('impersonation.supportUserName', $partnerUser->name));

    expect(session('impersonation.return_url'))->toBe(route('partner.properties.show', $property->code, absolute: false));
});

test('the start page of an onboarding property is its onboarding', function () {
    $property = Property::factory()->onboarding()->create();
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);

    $this->actingAs($this->admin)
        ->post(route('view-as.store', $property->code), viewAsInput($owner))
        ->assertRedirect(route('onboarding.basics.edit', $property->code, absolute: false));
});

test('a suspended partner cannot start a session', function () {
    $partnerUser = User::factory()->partner(Partner::factory()->create(['status' => PartnerStatus::Suspended]))->create();
    $property = Property::factory()->create(['partner_id' => $partnerUser->partner_id]);
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);

    $this->actingAs($partnerUser)
        ->post(route('view-as.store', $property->code), viewAsInput($owner))
        ->assertRedirect(route('login'));

    expect(session('impersonation'))->toBeNull();
});

test('a partner who is not active cannot start a session', function () {
    $partnerUser = User::factory()->partner(Partner::factory()->create(['status' => PartnerStatus::Invited]))->create();
    $property = Property::factory()->create(['partner_id' => $partnerUser->partner_id]);
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);

    $this->actingAs($partnerUser)
        ->post(route('view-as.store', $property->code), viewAsInput($owner))
        ->assertNotFound();
});

test('consent off blocks starting', function () {
    PropertySetting::factory()->for($this->property)->create(['allow_support_access' => false]);

    $this->actingAs($this->admin)
        ->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner))
        ->assertRedirect();

    expect(session('impersonation'))->toBeNull();
    $this->get(route('view-as.show', 'ES10142'))
        ->assertInertia(fn (Assert $page) => $page->where('consent', false));
});

test('a non-GET request from a support user without a session is a 403', function () {
    $this->actingAs($this->admin)
        ->post('/p/ES10142/settings/logo')
        ->assertForbidden();

    $partnerUser = User::factory()->partner()->create();
    $property = Property::factory()->create(['partner_id' => $partnerUser->partner_id]);
    $this->actingAs($partnerUser)->put("/p/{$property->code}/settings/general")->assertForbidden();

    $this->actingAs(User::factory()->create())->post('/p/ES10142/settings/logo')->assertNotFound();
});

test('an external or other-property to falls back to the dashboard', function (string $to) {
    $this->actingAs($this->admin)
        ->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner, ['to' => $to]))
        ->assertRedirect(route('dashboard', 'ES10142', absolute: false));
})->with([
    'full URL' => 'https://evil.example/p/ES10142/dashboard',
    'protocol-relative' => '//evil.example/p/ES10142/dashboard',
    'another property' => '/p/ES10199/dashboard',
    'not a staff route' => '/admin',
    'unknown path' => '/p/ES10142/nowhere',
    'plain text' => 'dashboard',
]);

test('the target must be active staff of that property', function () {
    $otherPropertyStaff = User::factory()->create();
    $deactivated = User::factory()->deactivated()->create(['property_id' => $this->property->id]);
    $partnerUser = User::factory()->partner()->create();

    foreach ([$this->admin, $partnerUser, $otherPropertyStaff, $deactivated] as $target) {
        $this->actingAs($this->admin)
            ->post(route('view-as.store', 'ES10142'), viewAsInput($target))
            ->assertSessionHasErrors('user');
    }

    expect(session('impersonation'))->toBeNull();
});

test('the reason is required and must be one of the chips', function () {
    $this->actingAs($this->admin)
        ->post(route('view-as.store', 'ES10142'), ['user' => $this->owner->id, 'reason' => 'curiosity'])
        ->assertSessionHasErrors('reason');
});

test('stop signs the support user back in and returns to the page they started from', function () {
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));

    $this->post(route('impersonation.stop'))
        ->assertRedirect(route('admin.properties.show', 'ES10142', absolute: false));

    expect(session('impersonation'))->toBeNull()
        ->and(auth()->id())->toBe($this->admin->id);

    $this->get(route('admin.dashboard'))->assertOk();
});

test('stop without a session goes home', function () {
    $this->actingAs($this->admin)->post(route('impersonation.stop'))->assertRedirect(route('home.redirect'));
});

test('an open session opens its own property in any tab and blocks another property', function () {
    $other = Property::factory()->create(['code' => 'ES10150']);
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));

    $this->get(route('view-as.show', ['property' => 'ES10142', 'to' => '/p/ES10142/settings/profile']))
        ->assertRedirect('/p/ES10142/settings/profile');

    $this->get(route('view-as.show', 'ES10150'))->assertRedirect(route('dashboard', 'ES10142'));
    $this->get('/p/ES10150/dashboard')
        ->assertRedirect(route('view-as.show', ['property' => 'ES10150', 'to' => '/p/ES10150/dashboard']));
    $this->post('/p/ES10150/settings/logo')->assertForbidden();
    expect($other->exists)->toBeTrue();
});

test('a staff user without a session cannot reach the view-as flow through a forged session', function () {
    $this->actingAs($this->owner)
        ->withSession(['impersonation' => ['support_user_id' => $this->admin->id, 'user_id' => $this->admin->id + 99, 'property_id' => $this->property->id, 'started_at' => now()->toIso8601String()]])
        ->get('/admin')
        ->assertNotFound();
});

test('logging out while impersonating ends the whole session', function () {
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));

    $this->post(route('logout'))->assertRedirect();

    expect(session('impersonation'))->toBeNull();
    $this->assertGuest();
});

test('the minutes left count down from 30', function () {
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));

    $this->travel(12)->minutes();

    $this->get(route('dashboard', 'ES10142'))
        ->assertInertia(fn (Assert $page) => $page->where('impersonation.minutesLeft', 18));
});

test('no impersonation prop for a signed-in admin', function () {
    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('impersonation', null));
});
