<?php

use App\Enums\PartnerStatus;
use App\Enums\TaxRateType;
use App\Models\Partner;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use App\Notifications\SupportAccessUsed;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

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

test('logging out while impersonating ends the whole session and logs it', function () {
    Notification::fake();
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));
    $this->travel(7)->minutes();

    $this->post(route('logout'))->assertRedirect();

    $ended = Activity::query()->where('event', 'impersonation.ended')->sole();

    expect(session('impersonation'))->toBeNull()
        ->and($ended->causer_id)->toBe($this->admin->id)
        ->and($ended->properties['duration_minutes'])->toBe(7);
    $this->assertGuest();
    Notification::assertSentTo($this->owner, SupportAccessUsed::class);
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

test('an admin session cannot reach admin pages or actions', function () {
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));

    $this->get(route('admin.dashboard'))->assertNotFound();
    $this->post(route('admin.properties.suspend', 'ES10142'))->assertNotFound();
});

test('a partner session cannot reach partner pages', function () {
    $partnerUser = User::factory()->partner()->create();
    $property = Property::factory()->create(['partner_id' => $partnerUser->partner_id]);
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);

    $this->actingAs($partnerUser)->post(route('view-as.store', $property->code), viewAsInput($owner));

    $this->get(route('partner.properties.index'))->assertNotFound();
});

test('a session opens the property for an owner who has not finished signing up', function (string $state) {
    $owner = User::factory()->owner()->{$state}()->create(['property_id' => $this->property->id]);

    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($owner));

    $this->get(route('dashboard', 'ES10142'))->assertOk();
})->with(['unverified', 'withTemporaryPassword']);

test('start and stop log the session with its facts and the duration', function () {
    $partnerUser = User::factory()->partner()->create();
    $property = Property::factory()->create(['partner_id' => $partnerUser->partner_id]);
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);

    $this->actingAs($partnerUser)->post(route('view-as.store', $property->code), viewAsInput($owner, ['reason' => 'setup_check', 'note' => 'Rates']));
    $this->travel(6)->minutes();
    $this->post(route('impersonation.stop'));

    $started = Activity::query()->where('event', 'impersonation.started')->sole();
    $ended = Activity::query()->where('event', 'impersonation.ended')->sole();

    expect($started->subject_id)->toBe($property->id)
        ->and($started->causer_id)->toBe($partnerUser->id)
        ->and($started->properties->all())->toMatchArray([
            'property_id' => $property->id,
            'support_user_id' => $partnerUser->id,
            'partner_id' => $partnerUser->partner_id,
            'partner_name' => $partnerUser->partner->name,
            'impersonated_user_id' => $owner->id,
            'reason' => 'setup_check',
            'note' => 'Rates',
        ])
        ->and($ended->causer_id)->toBe($partnerUser->id)
        ->and($ended->properties['duration_minutes'])->toBe(6)
        ->and($ended->properties['impersonated_user_id'])->toBe($owner->id);
});

test('stop says how long the session lasted', function () {
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));
    $this->travel(6)->minutes();

    $this->post(route('impersonation.stop'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Support session ended (6 min).']);
});

test('a write during a session records the support user as causer with the impersonated user', function () {
    $tax = Tax::factory()->for($this->property)->create();
    $payload = ['name' => 'City levy', 'code' => 'CITY', 'rate_type' => TaxRateType::Flat->value, 'rate' => '3.00', 'is_inclusive' => false, 'foreign_guests_only' => false, 'collect_at_check_in' => false, 'enabled' => true];

    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));
    $this->put(route('settings.taxes.update', ['ES10142', $tax]), $payload)->assertRedirect();

    $activity = Activity::query()->where('event', 'tax.updated')->sole();

    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['impersonated_user_id'])->toBe($this->owner->id);
});

test('a write outside a session keeps its own causer', function () {
    $tax = Tax::factory()->for($this->property)->create();
    $payload = ['name' => 'City levy', 'code' => 'CITY', 'rate_type' => TaxRateType::Flat->value, 'rate' => '3.00', 'is_inclusive' => false, 'foreign_guests_only' => false, 'collect_at_check_in' => false, 'enabled' => true];

    $this->actingAs($this->owner)->put(route('settings.taxes.update', ['ES10142', $tax]), $payload);

    $activity = Activity::query()->where('event', 'tax.updated')->sole();

    expect($activity->causer_id)->toBe($this->owner->id)
        ->and($activity->properties->has('impersonated_user_id'))->toBeFalse();
});

test('the owner is told when the session ends, with the partner name for a partner', function () {
    Notification::fake();
    $partnerUser = User::factory()->partner()->create();
    $property = Property::factory()->create(['partner_id' => $partnerUser->partner_id]);
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);
    $manager = User::factory()->create(['property_id' => $property->id]);

    $this->actingAs($partnerUser)->post(route('view-as.store', $property->code), viewAsInput($owner, ['note' => 'Call']));
    Notification::assertNothingSent();

    $this->travel(4)->minutes();
    $this->post(route('impersonation.stop'));

    Notification::assertSentTo($owner, SupportAccessUsed::class, fn (SupportAccessUsed $notification): bool => $notification->supportUserName === $partnerUser->name
        && $notification->partnerName === $partnerUser->partner->name
        && $notification->note === 'Call'
        && $notification->minutes === 4);
    Notification::assertNotSentTo($manager, SupportAccessUsed::class);
});

test('the notification follows the owner preferences', function () {
    $notification = new SupportAccessUsed('Sam', null, 'Support', null, now(), 5);

    expect($notification->via($this->owner))->toBe(['mail', 'database'])
        ->and($notification->toMail($this->owner)->subject)->toBe('Support viewed your property')
        ->and($notification->toArray($this->owner)['minutes'])->toBe(5);
});

test('a session ends on the next request after 30 minutes', function (string $url) {
    Notification::fake();
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));
    $this->travel(31)->minutes();

    $this->get($url)
        ->assertRedirect(route('admin.properties.show', 'ES10142', absolute: false))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Support session ended (30 min).']);

    $ended = Activity::query()->where('event', 'impersonation.ended')->sole();

    expect(session('impersonation'))->toBeNull()
        ->and(auth()->id())->toBe($this->admin->id)
        ->and($ended->causer_id)->toBe($this->admin->id)
        ->and($ended->properties['duration_minutes'])->toBe(30);
    Notification::assertSentTo($this->owner, SupportAccessUsed::class);
    $this->get(route('admin.dashboard'))->assertOk();
})->with([
    'staff page' => '/p/ES10142/dashboard',
    'account page' => '/account/profile',
]);

test('a session still open at 29 minutes is left alone', function () {
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));
    $this->travel(29)->minutes();

    $this->get(route('dashboard', 'ES10142'))->assertOk();

    expect(Activity::query()->where('event', 'impersonation.ended')->exists())->toBeFalse();
});

test('opening another property during a session asks to switch', function () {
    Property::factory()->create(['code' => 'ES10150', 'name' => 'KL Backpackers']);
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));

    $this->get(route('view-as.show', 'ES10150'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('view-as/show')
            ->where('switchFrom.code', 'ES10142')
            ->where('switchFrom.name', $this->property->name)
            ->where('cancelUrl', route('dashboard', 'ES10142', absolute: false))
            ->has('adminNavigation')
            ->where('property.code', 'ES10150'));
});

test('confirming a switch ends the old session and starts the new one', function () {
    Notification::fake();
    $other = Property::factory()->create(['code' => 'ES10150']);
    $otherOwner = User::factory()->owner()->create(['property_id' => $other->id]);
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));
    $this->travel(3)->minutes();

    $this->post(route('view-as.store', 'ES10150'), viewAsInput($otherOwner))
        ->assertRedirect(route('dashboard', 'ES10150', absolute: false));

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['impersonation.started', 'impersonation.ended', 'impersonation.started'])
        ->and(Activity::query()->where('event', 'impersonation.ended')->sole()->subject_id)->toBe($this->property->id)
        ->and(Activity::query()->where('event', 'impersonation.started')->latest('id')->first()->subject_id)->toBe($other->id)
        ->and(session('impersonation.property_id'))->toBe($other->id)
        ->and(session('impersonation.support_user_id'))->toBe($this->admin->id);
    Notification::assertSentTo($this->owner, SupportAccessUsed::class);

    $this->get(route('dashboard', 'ES10150'))->assertOk();
});

test('a refused switch keeps the old session', function () {
    $other = Property::factory()->create(['code' => 'ES10150']);
    PropertySetting::factory()->for($other)->create(['allow_support_access' => false]);
    $otherOwner = User::factory()->owner()->create(['property_id' => $other->id]);
    $this->actingAs($this->admin)->post(route('view-as.store', 'ES10142'), viewAsInput($this->owner));

    $this->post(route('view-as.store', 'ES10150'), viewAsInput($otherOwner));

    expect(session('impersonation.property_id'))->toBe($this->property->id)
        ->and(Activity::query()->where('event', 'impersonation.ended')->exists())->toBeFalse();
});

test('an admin is told an unknown code was not found, and a partner gets a 404', function () {
    $this->actingAs($this->admin)
        ->get(route('view-as.show', 'ZZ99999'))
        ->assertRedirect(route('admin.properties.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Property ZZ99999 not found.']);

    $this->actingAs(User::factory()->partner()->create())->get('/view-as/ZZ99999')->assertNotFound();
    $this->actingAs($this->owner)->get('/view-as/ZZ99999')->assertNotFound();
    $this->actingAs($this->admin)->post('/view-as/ZZ99999')->assertNotFound();
});
