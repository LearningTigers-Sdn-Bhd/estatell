<?php

require_once __DIR__.'/../Support/BookingApiSetup.php';

use App\Enums\BookingEngineMode;
use App\Features\BookingEngine;
use App\Models\BookingClient;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

const MODE_TEST_TOKEN = 'est_live_modetesttokencccccccccccccccccccccccccc';

function setModeUrl(Property $property): string
{
    return route('admin.properties.booking-engine-mode.update', $property->code);
}

/**
 * A property with the Booking engine flag on and a published microsite.
 *
 * @return array{property: Property, site: PropertyBookingSite, admin: User}
 */
function modeSetup(): array
{
    $property = Property::factory()->create();
    Feature::for($property)->activate(BookingEngine::class);

    return [
        'property' => $property,
        'site' => PropertyBookingSite::factory()->for($property)->published()->create(),
        'admin' => User::factory()->admin()->withTwoFactor()->create(),
    ];
}

test('the Features tab sends the booking engine mode', function () {
    ['property' => $property, 'site' => $site] = modeSetup();
    $site->update(['mode' => BookingEngineMode::ApiOnly]);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.properties.features', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bookingEngineMode.mode', 'api-only')
            ->has('bookingEngineMode.options', 2));
});

test('a property whose site was never opened shows Microsite', function () {
    $property = Property::factory()->create();

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.properties.features', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('bookingEngineMode.mode', 'microsite'));
});

test('switching to API only takes the microsite offline and is logged', function () {
    ['property' => $property, 'site' => $site, 'admin' => $admin] = modeSetup();

    $this->actingAs($admin)->put(setModeUrl($property), ['mode' => 'api-only'])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Booking engine mode set to API only.']);

    $site->refresh();
    expect($site->mode)->toBe(BookingEngineMode::ApiOnly)
        ->and($site->published_at)->toBeNull()
        ->and(Activity::query()->where('event', 'booking-website.unpublished')->exists())->toBeTrue();

    $log = Activity::query()->where('event', 'booking-website.mode-changed')->sole();
    expect($log->causer->is($admin))->toBeTrue()
        ->and($log->properties->only(['from', 'to'])->all())->toBe(['from' => 'microsite', 'to' => 'api-only']);
});

test('switching back to Microsite revokes the API token', function () {
    ['property' => $property, 'site' => $site, 'admin' => $admin] = bookingModeApiOnly();

    bookingApiGet('properties/'.$property->code, MODE_TEST_TOKEN)->assertOk();

    $this->actingAs($admin)->put(setModeUrl($property), ['mode' => 'microsite'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Booking engine mode set to Microsite.']);

    bookingApiGet('properties/'.$property->code, MODE_TEST_TOKEN)->assertUnauthorized();
    expect($site->refresh()->mode)->toBe(BookingEngineMode::Microsite)
        ->and(BookingClient::query()->sole()->revoked_at)->not->toBeNull();
});

/**
 * A reachable property in API only mode with a working API only token, and an admin.
 *
 * @return array{property: Property, site: PropertyBookingSite, admin: User}
 */
function bookingModeApiOnly(): array
{
    $setup = bookingApiProperty();
    $setup['site']->update(['mode' => BookingEngineMode::ApiOnly]);
    BookingClient::factory()->apiOnly($setup['property'])->withToken(MODE_TEST_TOKEN)->create();

    return ['property' => $setup['property'], 'site' => $setup['site'], 'admin' => User::factory()->admin()->withTwoFactor()->create()];
}

test('the same mode, or the flag off, changes nothing', function () {
    ['property' => $property, 'site' => $site, 'admin' => $admin] = modeSetup();

    $this->actingAs($admin)->put(setModeUrl($property), ['mode' => 'microsite'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Turn on the booking engine first, or pick another mode.']);

    Feature::for($property)->deactivate(BookingEngine::class);

    $this->actingAs($admin)->put(setModeUrl($property), ['mode' => 'api-only'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Turn on the booking engine first, or pick another mode.']);

    expect($site->refresh()->mode)->toBe(BookingEngineMode::Microsite)
        ->and($site->published_at)->not->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('an unknown mode is refused', function () {
    ['property' => $property, 'admin' => $admin] = modeSetup();

    $this->actingAs($admin)->put(setModeUrl($property), ['mode' => 'kiosk'])->assertSessionHasErrors('mode');
});

test('only admins with two-factor set the mode', function () {
    ['property' => $property, 'site' => $site] = modeSetup();
    config(['estatell.support_require_two_factor' => true]);

    $this->actingAs(User::factory()->owner()->create(['property_id' => $property->id]))->put(setModeUrl($property), ['mode' => 'api-only'])->assertNotFound();
    $this->actingAs(User::factory()->partner()->create())->put(setModeUrl($property), ['mode' => 'api-only'])->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->put(setModeUrl($property), ['mode' => 'api-only'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect($site->refresh()->mode)->toBe(BookingEngineMode::Microsite);
});
