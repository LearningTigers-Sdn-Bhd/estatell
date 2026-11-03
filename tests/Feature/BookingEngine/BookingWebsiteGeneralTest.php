<?php

use App\Actions\BookingEngine\SetUpBookingSite;
use App\Enums\BookingEngineMode;
use App\Enums\PermissionEnum;
use App\Features\BookingEngine;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * An owner of a property with the Booking engine on.
 *
 * @return array{owner: User, property: Property}
 */
function bookingWebsiteSetup(bool $feature = true): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live', 'name' => 'Sand Bay Resort'])->save();

    if ($feature) {
        Feature::for($property)->activate(BookingEngine::class);
    }

    return compact('owner', 'property');
}

it('answers 404 while the Booking engine feature is off', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup(feature: false);

    $this->actingAs($owner)->get(route('settings.booking-website.general', $property))->assertNotFound();
    $this->actingAs($owner)->post(route('settings.booking-website.publish', $property))->assertNotFound();
});

it('needs the manage permission to open the page', function () {
    ['property' => $property] = bookingWebsiteSetup();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::SettingsManage])->create();

    $this->actingAs($staff)->get(route('settings.booking-website.general', $property))->assertForbidden();
});

it('answers 404 for another property\'s code', function () {
    ['owner' => $owner] = bookingWebsiteSetup();
    $other = Property::factory()->create();

    $this->actingAs($owner)->get(route('settings.booking-website.general', $other))->assertNotFound();
});

it('sets up the site with a slug from the property name on first visit', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();

    $this->actingAs($owner)->get(route('settings.booking-website.general', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/booking-website/general')
            ->where('general.slug', 'sand-bay-resort')
            ->where('general.mode', 'microsite')
            ->where('general.published', false)
            ->where('general.canPublish', false)
            ->where('general.checklist.0.key', 'template')
            ->where('general.checklist.0.done', false)
            ->where('general.checklist.1.key', 'location')
            ->where('general.checklist.1.done', false));

    expect($property->refresh()->slug)->toBe('sand-bay-resort')
        ->and(PropertyBookingSite::query()->where('property_id', $property->id)->count())->toBe(1);
});

it('keeps the slug and the row when it is set up twice', function () {
    ['property' => $property] = bookingWebsiteSetup();

    app(SetUpBookingSite::class)($property);
    $property->forceFill(['slug' => 'my-own'])->save();
    app(SetUpBookingSite::class)($property->refresh());

    expect($property->refresh()->slug)->toBe('my-own')
        ->and(PropertyBookingSite::query()->where('property_id', $property->id)->count())->toBe(1);
});

it('adds a number to a slug another property already uses', function () {
    ['property' => $property] = bookingWebsiteSetup();
    Property::factory()->create(['slug' => 'sand-bay-resort']);

    app(SetUpBookingSite::class)($property);

    expect($property->refresh()->slug)->toBe('sand-bay-resort-2');
});

it('saves the slug and the Google Maps link, reads the location from it and logs the change', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    app(SetUpBookingSite::class)($property);

    $this->actingAs($owner)->put(route('settings.booking-website.general.update', $property), [
        'slug' => 'sand-bay',
        'google_maps_url' => 'https://www.google.com/maps/place/Sand+Bay/@4.2105,101.9758,15z',
    ])->assertRedirect(route('settings.booking-website.general', $property));

    expect($property->refresh()->slug)->toBe('sand-bay')
        ->and($property->google_maps_url)->toBe('https://www.google.com/maps/place/Sand+Bay/@4.2105,101.9758,15z')
        ->and((float) $property->latitude)->toBe(4.2105)
        ->and((float) $property->longitude)->toBe(101.9758)
        ->and(Activity::query()->where('event', 'booking-website.general-saved')->count())->toBe(1);
});

it('refuses a bad or taken slug', function (string $slug) {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    app(SetUpBookingSite::class)($property);
    Property::factory()->create(['slug' => 'taken-name']);

    $this->actingAs($owner)->put(route('settings.booking-website.general.update', $property), [
        'slug' => $slug,
    ])->assertSessionHasErrors('slug');
})->with(['Upper Case', 'double--hyphen', '-leading', 'ab', 'taken-name']);

it('clears the location when the Google Maps link is removed', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    app(SetUpBookingSite::class)($property);
    $property->forceFill(['google_maps_url' => 'https://www.google.com/maps/place/Sand+Bay/@4.2105,101.9758,15z', 'latitude' => 4.2105, 'longitude' => 101.9758])->save();

    $this->actingAs($owner)->put(route('settings.booking-website.general.update', $property), [
        'slug' => $property->slug,
    ])->assertSessionHasNoErrors();

    expect($property->refresh()->google_maps_url)->toBeNull()
        ->and($property->latitude)->toBeNull()
        ->and($property->longitude)->toBeNull();
});

it('refuses a Google Maps link with no place name and keeps the old location', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    app(SetUpBookingSite::class)($property);

    $this->actingAs($owner)->put(route('settings.booking-website.general.update', $property), [
        'slug' => 'sand-bay',
        'google_maps_url' => 'https://www.google.com/maps/@4.2105,101.9758,15z',
    ])->assertSessionHasErrors('google_maps_url');

    expect($property->refresh()->latitude)->toBeNull();
});

it('refuses a Google Maps link that is not a web address', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    app(SetUpBookingSite::class)($property);

    $this->actingAs($owner)->put(route('settings.booking-website.general.update', $property), [
        'slug' => 'sand-bay',
        'google_maps_url' => 'javascript:alert(1)',
    ])->assertSessionHasErrors('google_maps_url');
});

it('keeps the slug once the site is published', function () {
    Http::fake(['maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/Sand+Bay/@4.2105,101.9758,15z'])]);
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    app(SetUpBookingSite::class)($property);
    $property->bookingSite->forceFill(['published_at' => now()])->save();

    $this->actingAs($owner)->put(route('settings.booking-website.general.update', $property), [
        'slug' => 'new-name',
    ])->assertSessionHasErrors('slug');

    $this->actingAs($owner)->put(route('settings.booking-website.general.update', $property), [
        'slug' => $property->slug,
        'google_maps_url' => 'https://maps.app.goo.gl/abc',
    ])->assertSessionHasNoErrors();

    Http::assertSentCount(1);
});

it('refuses to publish while the template is missing, and writes nothing', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    app(SetUpBookingSite::class)($property);

    $this->actingAs($owner)->post(route('settings.booking-website.publish', $property))
        ->assertRedirect(route('settings.booking-website.general', $property));

    expect($property->bookingSite()->first()->published_at)->toBeNull()
        ->and(Activity::query()->where('event', 'booking-website.published')->exists())->toBeFalse();
});

it('publishes and unpublishes once the checklist is done and logs who did it', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    app(SetUpBookingSite::class)($property);
    $property->bookingSite->forceFill(['site_template_id' => 1])->save();

    $this->actingAs($owner)->post(route('settings.booking-website.publish', $property))->assertRedirect();
    expect($property->bookingSite()->first()->published_at)->toBeNull();

    $property->forceFill(['latitude' => 4.2105, 'longitude' => 101.9758])->save();

    $this->actingAs($owner)->post(route('settings.booking-website.publish', $property))->assertRedirect();
    expect($property->bookingSite()->first()->published_at)->not->toBeNull();

    $this->actingAs($owner)->post(route('settings.booking-website.publish', $property))->assertRedirect();
    expect(Activity::query()->where('event', 'booking-website.published')->count())->toBe(1);

    $this->actingAs($owner)->post(route('settings.booking-website.unpublish', $property))->assertRedirect();
    expect($property->bookingSite()->first()->published_at)->toBeNull()
        ->and(Activity::query()->where('event', 'booking-website.unpublished')->where('causer_id', $owner->id)->count())->toBe(1);
});

it('lets API only go live with no template row', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    $property->forceFill(['latitude' => 4.2105, 'longitude' => 101.9758])->save();
    PropertyBookingSite::factory()->apiOnly()->for($property)->create();

    $this->actingAs($owner)->get(route('settings.booking-website.general', $property))
        ->assertInertia(fn (Assert $page) => $page->where('general.mode', 'api-only')->where('general.canPublish', true)->has('general.checklist', 1));

    $this->actingAs($owner)->post(route('settings.booking-website.publish', $property))->assertRedirect();

    expect($property->bookingSite()->first()->mode)->toBe(BookingEngineMode::ApiOnly)
        ->and($property->bookingSite()->first()->isPublished())->toBeTrue();
});

it('needs the publish permission to publish', function () {
    ['property' => $property] = bookingWebsiteSetup();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::BookingWebsiteManage])->create();

    $this->actingAs($staff)->get(route('settings.booking-website.general', $property))->assertOk();
    $this->actingAs($staff)->post(route('settings.booking-website.publish', $property))->assertForbidden();
});

it('shows the Booking website item in the settings sidebar only when the flag is on', function () {
    ['owner' => $owner, 'property' => $property] = bookingWebsiteSetup();
    $other = bookingWebsiteSetup(feature: false);

    $this->actingAs($owner)->get(route('settings.profile', $property))
        ->assertSee('Booking website', false);
    $this->actingAs($other['owner'])->get(route('settings.profile', $other['property']))
        ->assertDontSee('Booking website', false);
});
