<?php

use App\Enums\PropertyStatus;
use App\Http\Middleware\EnsurePropertyAccess;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function statusRoute(Property $property, string $action): string
{
    return route("admin.properties.{$action}", $property->code);
}

test('suspension records the former status and admin activity', function (PropertyStatus $status) {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create(['status' => $status]);

    $this->actingAs($admin)
        ->post(statusRoute($property, 'suspend'))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Property suspended.']);

    expect($property->refresh()->status)->toBe(PropertyStatus::Suspended)
        ->and($property->suspended_from_status)->toBe($status)
        ->and($property->suspended_at)->not->toBeNull();

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('property.suspended')
        ->and($activity->causer->is($admin))->toBeTrue()
        ->and($activity->subject->is($property))->toBeTrue()
        ->and($activity->properties->all())->toBe(['from' => $status->value, 'to' => 'suspended']);
})->with([PropertyStatus::Onboarding, PropertyStatus::PendingApproval, PropertyStatus::Live]);

test('reactivation restores the prior status and clears suspension fields', function (PropertyStatus $status) {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create(['status' => $status]);

    $this->actingAs($admin)->post(statusRoute($property, 'suspend'))->assertRedirect();
    $this->post(statusRoute($property, 'reactivate'))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Property reactivated.']);

    expect($property->refresh()->status)->toBe($status)
        ->and($property->suspended_from_status)->toBeNull()
        ->and($property->suspended_at)->toBeNull();

    $activity = Activity::query()->orderByDesc('id')->firstOrFail();

    expect($activity->event)->toBe('property.reactivated')
        ->and($activity->causer->is($admin))->toBeTrue()
        ->and($activity->subject->is($property))->toBeTrue()
        ->and($activity->properties->all())->toBe(['from' => 'suspended', 'to' => $status->value]);
})->with([PropertyStatus::Onboarding, PropertyStatus::PendingApproval, PropertyStatus::Live]);

test('repeated and invalid transitions flash an error without another activity', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();

    $this->actingAs($admin)->post(statusRoute($property, 'reactivate'))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This property is not suspended.']);
    expect(Activity::query()->count())->toBe(0);

    $this->post(statusRoute($property, 'suspend'))->assertRedirect();
    $suspendedAt = $property->refresh()->suspended_at;

    $this->post(statusRoute($property, 'suspend'))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This property cannot be suspended.']);
    expect($property->refresh()->suspended_at->equalTo($suspendedAt))->toBeTrue()
        ->and(Activity::query()->count())->toBe(1);

    $this->post(statusRoute($property, 'reactivate'))->assertRedirect();
    $this->post(statusRoute($property, 'reactivate'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This property is not suspended.']);
    expect(Activity::query()->count())->toBe(2);
});

test('staff cannot use admin status actions', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->post(statusRoute($staff->property, 'suspend'))->assertNotFound();
    $this->post(statusRoute($staff->property, 'reactivate'))->assertNotFound();

    expect($staff->property->refresh()->status)->toBe(PropertyStatus::Onboarding)
        ->and(Activity::query()->count())->toBe(0);
});

test('guests must sign in for admin status actions', function () {
    $property = Property::factory()->create();

    $this->post(statusRoute($property, 'suspend'))->assertRedirect(route('login'));
    $this->post(statusRoute($property, 'reactivate'))->assertRedirect(route('login'));
});

test('two-factor is required for both admin status actions', function (string $action) {
    config(['estatell.admin_require_two_factor' => true]);
    $property = Property::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post(statusRoute($property, $action))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect($property->refresh()->status)->toBe(PropertyStatus::Onboarding)
        ->and(Activity::query()->count())->toBe(0);
})->with(['suspend', 'reactivate']);

test('unknown property codes are not found for admin status actions', function (string $action) {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(route("admin.properties.{$action}", 99999999))
        ->assertNotFound();
})->with(['suspend', 'reactivate']);

test('suspended staff see the notice instead of property pages and can open their account', function () {
    $staff = User::factory()->create();
    $property = $staff->property;
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->post(statusRoute($property, 'suspend'))->assertRedirect();

    $this->actingAs($staff)
        ->get(route('dashboard', $property->code))
        ->assertRedirect(route('property.suspended', $property->code));

    $this->get(route('property.suspended', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('property/suspended'));

    $this->get(route('account.profile.edit'))->assertOk();
});

test('suspended staff cannot reach a property write route', function () {
    $staff = User::factory()->create();
    $property = $staff->property;

    Route::middleware(['web', EnsurePropertyAccess::class])
        ->post('/p/{property:code}/_write', fn () => response()->noContent());

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(statusRoute($property, 'suspend'));

    $this->actingAs($staff)
        ->post("/p/{$property->code}/_write")
        ->assertRedirect(route('property.suspended', $property->code));
});

test('staff cannot view another property suspension notice', function () {
    $staff = User::factory()->create();
    $other = Property::factory()->suspended()->create();

    $this->actingAs($staff)
        ->get(route('property.suspended', $other->code))
        ->assertNotFound();
});

test('an active property redirects a stale suspension notice to the dashboard', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('property.suspended', $staff->property->code))
        ->assertRedirect(route('dashboard', $staff->property->code));
});
