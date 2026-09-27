<?php

use App\Enums\PlatformFeature;
use App\Features\ChannelManager;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

function updateFeature(Property $property, bool $active, string $feature = 'channel-manager'): string
{
    return route('admin.properties.features.update', ['property' => $property->code, 'feature' => $feature]);
}

test('a new property has the channel manager off', function () {
    expect(Feature::for(Property::factory()->create())->active(ChannelManager::class))->toBeFalse();
});

test('the stored scope and feature name are stable identifiers', function () {
    $property = Property::factory()->create();

    Feature::for($property)->activate(ChannelManager::class);

    expect(DB::table('features')->where('name', 'channel-manager')->value('scope'))->toBe("property:{$property->id}");
});

test('the default scope is the property in the URL or the staff user\'s property', function () {
    $staff = User::factory()->create();
    $other = Property::factory()->create();
    Feature::for($other)->activate(ChannelManager::class);

    Route::middleware('web')->get('/_scope/{property}', fn () => ['active' => Feature::active(ChannelManager::class)]);
    Route::middleware('web')->get('/_scope', fn () => ['active' => Feature::active(ChannelManager::class)]);

    $this->actingAs($staff)->get("/_scope/{$other->code}")->assertJson(['active' => true]);
    $this->actingAs($staff)->get('/_scope')->assertJson(['active' => false]);

    Feature::for($staff->property)->activate(ChannelManager::class);
    Feature::flushCache();

    $this->actingAs($staff)->get('/_scope')->assertJson(['active' => true]);
    $this->actingAs(User::factory()->admin()->create())->get('/_scope')->assertJson(['active' => false]);
});

test('the property page lists features with their state for that property only', function () {
    $property = Property::factory()->create();
    Feature::for(Property::factory()->create())->activate(ChannelManager::class);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.properties.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('features', count(PlatformFeature::cases()))
            ->where('features.0', [
                'feature' => 'channel-manager',
                'label' => 'Channel manager',
                'description' => 'Connect this property to Channex and sync OTAs.',
                'active' => false,
            ])
        );
});

test('admins turn a feature on and off and each change is logged', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();

    $this->actingAs($admin)
        ->put(updateFeature($property, true), ['active' => true])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Channel manager turned on.']);

    expect(Feature::for($property)->active(ChannelManager::class))->toBeTrue();

    $this->actingAs($admin)->put(updateFeature($property, false), ['active' => false]);
    Feature::flushCache();

    expect(Feature::for($property)->active(ChannelManager::class))->toBeFalse()
        ->and(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['feature.activated', 'feature.deactivated'])
        ->and(Activity::query()->first()->causer->is($admin))->toBeTrue()
        ->and(Activity::query()->first()->subject->is($property))->toBeTrue()
        ->and(Activity::query()->first()->properties->get('feature'))->toBe('channel-manager');
});

test('setting the same value again writes no log entry', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();

    $this->actingAs($admin)->put(updateFeature($property, false), ['active' => false])->assertRedirect();

    expect(Activity::query()->count())->toBe(0);
});

test('staff get a 404 on the feature switch', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->put(updateFeature($staff->property, true), ['active' => true])
        ->assertNotFound();

    expect(Feature::for($staff->property)->active(ChannelManager::class))->toBeFalse();
});

test('admins without two-factor cannot change features', function () {
    config(['estatell.admin_require_two_factor' => true]);
    $property = Property::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(updateFeature($property, true), ['active' => true])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect(Feature::for($property)->active(ChannelManager::class))->toBeFalse();
});

test('an unknown feature is a 404', function () {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(updateFeature(Property::factory()->create(), true, 'time-travel'), ['active' => true])
        ->assertNotFound();
});

test('active is required', function () {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(updateFeature(Property::factory()->create(), true), [])
        ->assertSessionHasErrors('active');
});
