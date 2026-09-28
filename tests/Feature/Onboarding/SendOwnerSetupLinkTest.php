<?php

use App\Actions\Onboarding\SendOwnerSetupLink;
use App\Models\Property;
use App\Models\User;
use App\Notifications\OwnerSetupNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

test('an admin can send a fresh setup link to an unverified owner', function () {
    $owner = User::factory()->unverified()->owner()->create();

    expect(app(SendOwnerSetupLink::class)($this->admin, $owner->property))->toBeTrue();

    Notification::assertSentTo($owner, OwnerSetupNotification::class);
    $activity = Activity::query()->sole();
    expect($activity->event)->toBe('owner.setup-link-sent')
        ->and($activity->causer->is($this->admin))->toBeTrue()
        ->and($activity->subject->is($owner))->toBeTrue()
        ->and($activity->properties->get('property_id'))->toBe($owner->property_id);
});

test('sending is refused without an unverified owner', function (bool $withVerifiedOwner) {
    $property = $withVerifiedOwner
        ? User::factory()->owner()->create()->property
        : Property::factory()->create();

    expect(app(SendOwnerSetupLink::class)($this->admin, $property))->toBeFalse()
        ->and(Activity::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'verified owner' => true,
    'no owner' => false,
]);

test('the send endpoint requires an admin with two-factor', function () {
    config(['estatell.admin_require_two_factor' => true]);
    $owner = User::factory()->unverified()->owner()->create();
    $route = route('admin.properties.owner.setup-link', $owner->property->code);

    $this->post($route)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->post($route)->assertNotFound();
    $this->actingAs($this->admin)->post($route)
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);
    Notification::assertNothingSent();

    $admin = User::factory()->admin()->withTwoFactor()->create();
    $this->actingAs($admin)->post($route)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Owner setup email sent.']);
    Notification::assertSentTo($owner, OwnerSetupNotification::class);
});

test('the send endpoint reports a verified owner without writing activity', function () {
    config(['estatell.admin_require_two_factor' => false]);
    $owner = User::factory()->owner()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.properties.owner.setup-link', $owner->property->code))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This property has no unverified owner.']);

    expect(Activity::query()->count())->toBe(0);
    Notification::assertNothingSent();
});
