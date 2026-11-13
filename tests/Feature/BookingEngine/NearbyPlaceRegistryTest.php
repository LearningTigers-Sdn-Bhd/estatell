<?php

use App\Actions\BookingEngine\RejectNearbyPlace;
use App\Enums\NearbyPlaceStatus;
use App\Enums\PermissionEnum;
use App\Features\BookingEngine;
use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\User;
use App\Notifications\NearbyPlaceRemoved;
use App\Services\BookingEngine\GoogleMapsLink;
use App\Services\BookingEngine\InvalidMapsLink;
use App\Services\BookingEngine\NearbyPlaceSuggestions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

const PANGKOR_JETTY = 'https://www.google.com/maps/place/Pangkor+Jetty/@4.2297,100.5535,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d4.2297123!4d100.5535456';

/**
 * @return array{owner: User, property: Property}
 */
function nearbyOwner(float $latitude = 4.2105, float $longitude = 100.5535): array
{
    $owner = User::factory()->owner()->create();
    $owner->property->forceFill(['latitude' => $latitude, 'longitude' => $longitude])->save();
    Feature::for($owner->property)->activate(BookingEngine::class);

    return ['owner' => $owner, 'property' => $owner->property];
}

describe('GoogleMapsLink', function () {
    it('reads the name and the pin coordinates from a place link', function () {
        $place = app(GoogleMapsLink::class)->parse(PANGKOR_JETTY);

        expect($place->name)->toBe('Pangkor Jetty')
            ->and($place->latitude)->toBe('4.2297123')
            ->and($place->longitude)->toBe('100.5535456')
            ->and($place->fingerprint)->toBe('pangkor jetty|4.2297|100.5535');
    });

    it('falls back to the map view coordinates', function () {
        $place = app(GoogleMapsLink::class)->parse('https://www.google.com/maps/place/Pasar+Malam/@2.1896,102.2501,15z');

        expect($place->latitude)->toBe('2.1896')->and($place->longitude)->toBe('102.2501');
    });

    it('follows one redirect for a short link and reads the result', function () {
        Http::fake(['maps.app.goo.gl/*' => Http::response('', 302, ['Location' => PANGKOR_JETTY])]);

        $place = app(GoogleMapsLink::class)->parse('https://maps.app.goo.gl/abc123');

        expect($place->name)->toBe('Pangkor Jetty');
        Http::assertSentCount(1);
    });

    it('refuses a short link that redirects off Google Maps', function () {
        Http::fake(['maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://evil.example/maps/place/X/@1,2'])]);

        app(GoogleMapsLink::class)->parse('https://maps.app.goo.gl/abc123');
    })->throws(InvalidMapsLink::class);

    it('never opens a host that is not Google Maps', function () {
        Http::fake();

        try {
            app(GoogleMapsLink::class)->parse('https://evil.example/maps/place/X/@1,2');
        } catch (InvalidMapsLink) {
        }

        Http::assertNothingSent();
    });

    it('asks for a link with a place name', function () {
        app(GoogleMapsLink::class)->parse('https://www.google.com/maps/@4.2105,101.9758,15z');
    })->throws(InvalidMapsLink::class, 'This link has no place name. Open the place on Google Maps and copy its link.');

    it('refuses coordinates out of range', function () {
        app(GoogleMapsLink::class)->parse('https://www.google.com/maps/place/X/@95.0,101.9,15z');
    })->throws(InvalidMapsLink::class);
});

describe('owner Nearby places tab', function () {
    it('adds a new place as Pending and reuses it for a second property', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        ['owner' => $otherOwner, 'property' => $otherProperty] = nearbyOwner();

        $this->actingAs($owner)->from(route('settings.booking-website.nearby-places', $property))->post(route('settings.booking-website.nearby-places.store', $property), ['google_maps_url' => PANGKOR_JETTY])
            ->assertRedirect(route('settings.booking-website.nearby-places', $property));
        $this->actingAs($otherOwner)->post(route('settings.booking-website.nearby-places.store', $otherProperty), ['google_maps_url' => PANGKOR_JETTY]);

        $place = NearbyPlace::query()->sole();

        expect($place->status)->toBe(NearbyPlaceStatus::Pending)
            ->and($place->created_by_property_id)->toBe($property->id)
            ->and($property->nearbyPlaces()->count())->toBe(1)
            ->and($otherProperty->nearbyPlaces()->count())->toBe(1)
            ->and(Activity::query()->where('event', 'property.nearby_place_added')->count())->toBe(2);
    });

    it('does not add the same place twice', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();

        $this->actingAs($owner)->post(route('settings.booking-website.nearby-places.store', $property), ['google_maps_url' => PANGKOR_JETTY]);
        $this->actingAs($owner)->post(route('settings.booking-website.nearby-places.store', $property), ['google_maps_url' => PANGKOR_JETTY]);

        expect($property->nearbyPlaces()->count())->toBe(1)
            ->and(NearbyPlace::query()->count())->toBe(1);
    });

    it('does not add a rejected place', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        NearbyPlace::factory()->status(NearbyPlaceStatus::Rejected)->create([
            'name' => 'Pangkor Jetty',
            'fingerprint' => 'pangkor jetty|4.2297|100.5535',
        ]);

        $this->actingAs($owner)->post(route('settings.booking-website.nearby-places.store', $property), ['google_maps_url' => PANGKOR_JETTY]);

        expect($property->nearbyPlaces()->count())->toBe(0);
    });

    it('shows a validation error for a link it cannot read', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();

        $this->actingAs($owner)->post(route('settings.booking-website.nearby-places.store', $property), ['google_maps_url' => 'https://www.google.com/maps/@4.2,101.9,15z'])
            ->assertSessionHasErrors('google_maps_url');

        expect(NearbyPlace::query()->count())->toBe(0);
    });

    it('shows places in order without the status', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        $second = NearbyPlace::factory()->status(NearbyPlaceStatus::Pending)->create(['name' => 'Beach']);
        $first = NearbyPlace::factory()->create(['name' => 'Jetty']);
        $property->nearbyPlaces()->attach($second->id, ['position' => 2]);
        $property->nearbyPlaces()->attach($first->id, ['position' => 1, 'description' => 'Ferry to the island']);

        $this->actingAs($owner)->get(route('settings.booking-website.nearby-places', $property))
            ->assertInertia(fn (Assert $page) => $page
                ->has('nearbyPlaces', 2)
                ->where('nearbyPlaces.0.name', 'Jetty')
                ->where('nearbyPlaces.0.description', 'Ferry to the island')
                ->where('nearbyPlaces.1.name', 'Beach')
                ->missing('nearbyPlaces.0.status'));
    });

    it('saves a description, reorders and removes a place', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        [$a, $b] = NearbyPlace::factory()->count(2)->create();
        $property->nearbyPlaces()->attach($a->id, ['position' => 1]);
        $property->nearbyPlaces()->attach($b->id, ['position' => 2]);

        $this->actingAs($owner)->put(route('settings.booking-website.nearby-places.update', [$property, $a]), ['description' => '  Five minutes by car '])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('settings.booking-website.nearby-places.reorder', $property), ['order' => [$b->id, $a->id]])->assertSessionHasNoErrors();

        expect($property->nearbyPlaces()->pluck('nearby_places.id')->all())->toBe([$b->id, $a->id])
            ->and($property->nearbyPlaces()->whereKey($a->id)->first()->pivot->description)->toBe('Five minutes by car');

        $this->actingAs($owner)->delete(route('settings.booking-website.nearby-places.destroy', [$property, $a]))->assertRedirect();

        expect($property->nearbyPlaces()->pluck('nearby_places.id')->all())->toBe([$b->id])
            ->and(NearbyPlace::query()->count())->toBe(2);
    });

    it('shows the property\'s own name on that property only', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        ['owner' => $otherOwner, 'property' => $other] = nearbyOwner();
        $place = NearbyPlace::factory()->create(['name' => 'Shared name']);
        $property->nearbyPlaces()->attach($place->id, ['position' => 1]);
        $other->nearbyPlaces()->attach($place->id, ['position' => 1]);

        $this->actingAs($owner)->put(route('settings.booking-website.nearby-places.update', [$property, $place]), ['display_name' => '  My name ', 'description' => ''])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('settings.booking-website.nearby-places', $property))
            ->assertInertia(fn (Assert $page) => $page->where('nearbyPlaces.0.name', 'My name')->where('nearbyPlaces.0.sharedName', 'Shared name'));
        $this->actingAs($otherOwner)->get(route('settings.booking-website.nearby-places', $other))
            ->assertInertia(fn (Assert $page) => $page->where('nearbyPlaces.0.name', 'Shared name'));

        expect($place->refresh()->name)->toBe('Shared name');
    });

    it('goes back to the shared name, and follows an admin rename, when the own name is empty or the same', function (string $displayName) {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        $place = NearbyPlace::factory()->create(['name' => 'Shared name']);
        $property->nearbyPlaces()->attach($place->id, ['position' => 1, 'display_name' => 'My name']);

        $this->actingAs($owner)->put(route('settings.booking-website.nearby-places.update', [$property, $place]), ['display_name' => $displayName])->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->admin()->create())->put(route('admin.nearby-places.update', $place), ['name' => 'Renamed']);

        $this->actingAs($owner)->get(route('settings.booking-website.nearby-places', $property))
            ->assertInertia(fn (Assert $page) => $page->where('nearbyPlaces.0.name', 'Renamed')->where('nearbyPlaces.0.displayName', null));
    })->with(['empty' => '', 'same as shared' => 'Shared name']);

    it('keeps the own name when an admin renames the place', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        $place = NearbyPlace::factory()->create();
        $property->nearbyPlaces()->attach($place->id, ['position' => 1, 'display_name' => 'My name']);

        $this->actingAs(User::factory()->admin()->create())->put(route('admin.nearby-places.update', $place), ['name' => 'Renamed']);

        $this->actingAs($owner)->get(route('settings.booking-website.nearby-places', $property))
            ->assertInertia(fn (Assert $page) => $page->where('nearbyPlaces.0.name', 'My name')->where('nearbyPlaces.0.sharedName', 'Renamed'));
    });

    it('turns a place off and keeps its row and description', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        $place = NearbyPlace::factory()->status(NearbyPlaceStatus::Pending)->create();
        $property->nearbyPlaces()->attach($place->id, ['position' => 1, 'description' => 'Ferry to the island']);

        $this->actingAs($owner)->put(route('settings.booking-website.nearby-places.active.update', [$property, $place]), ['active' => false])->assertRedirect();

        $this->actingAs($owner)->get(route('settings.booking-website.nearby-places', $property))
            ->assertInertia(fn (Assert $page) => $page
                ->where('nearbyPlaces.0.active', false)
                ->where('nearbyPlaces.0.description', 'Ferry to the island')
                ->has('suggestions', 0));

        expect(Activity::query()->where('event', 'property.nearby_place_turned_off')->count())->toBe(1);
    });

    it('puts a place turned back on at the end of the list', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        [$a, $b] = NearbyPlace::factory()->count(2)->create();
        $property->nearbyPlaces()->attach($a->id, ['position' => 1, 'active' => false]);
        $property->nearbyPlaces()->attach($b->id, ['position' => 2]);

        $this->actingAs($owner)->put(route('settings.booking-website.nearby-places.active.update', [$property, $a]), ['active' => true])->assertRedirect();

        expect($property->nearbyPlaces()->pluck('nearby_places.id')->all())->toBe([$b->id, $a->id])
            ->and($property->nearbyPlaces()->whereKey($a->id)->first()->pivot->active)->toBeTrue();
    });

    it('refuses a switch that changes nothing, or another property\'s place', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        ['property' => $other] = nearbyOwner();
        $own = NearbyPlace::factory()->create();
        $theirs = NearbyPlace::factory()->create();
        $property->nearbyPlaces()->attach($own->id, ['position' => 1]);
        $other->nearbyPlaces()->attach($theirs->id, ['position' => 1]);

        $this->actingAs($owner)->put(route('settings.booking-website.nearby-places.active.update', [$property, $own]), ['active' => true])
            ->assertInertiaFlash('toast.type', 'error');
        $this->actingAs($owner)->put(route('settings.booking-website.nearby-places.active.update', [$property, $theirs]), ['active' => false])
            ->assertNotFound();

        expect($other->nearbyPlaces()->whereKey($theirs->id)->first()->pivot->active)->toBeTrue()
            ->and(Activity::query()->where('event', 'like', 'property.nearby_place_turned_%')->exists())->toBeFalse();
    });

    it('sends the distance of own places, and none without a property location', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner(4.2105, 100.5535);
        $place = NearbyPlace::factory()->at(4.2305, 100.5535)->create();
        $property->nearbyPlaces()->attach($place->id, ['position' => 1]);

        $this->actingAs($owner)->get(route('settings.booking-website.nearby-places', $property))
            ->assertInertia(fn (Assert $page) => $page->where('nearbyPlaces.0.distance', '2.2 km'));

        $property->forceFill(['latitude' => null, 'longitude' => null])->save();

        $this->actingAs($owner)->get(route('settings.booking-website.nearby-places', $property))
            ->assertInertia(fn (Assert $page) => $page->where('nearbyPlaces.0.distance', null));
    });

    it('refuses a reorder that is not the full list', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        [$a, $b] = NearbyPlace::factory()->count(2)->create();
        $property->nearbyPlaces()->attach($a->id, ['position' => 1]);
        $property->nearbyPlaces()->attach($b->id, ['position' => 2]);

        $this->actingAs($owner)->put(route('settings.booking-website.nearby-places.reorder', $property), ['order' => [$b->id]]);

        expect($property->nearbyPlaces()->pluck('nearby_places.id')->all())->toBe([$a->id, $b->id]);
    });

    it('cannot touch another property\'s place', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        ['property' => $other] = nearbyOwner();
        $place = NearbyPlace::factory()->create();
        $other->nearbyPlaces()->attach($place->id, ['position' => 1]);

        $this->actingAs($owner)->delete(route('settings.booking-website.nearby-places.destroy', [$property, $place]))->assertNotFound();

        expect($other->nearbyPlaces()->count())->toBe(1);
    });

    it('needs the manage permission', function () {
        ['property' => $property] = nearbyOwner();
        $staff = User::factory()->for($property)->withPermissions([PermissionEnum::SettingsManage])->create();

        $this->actingAs($staff)->get(route('settings.booking-website.nearby-places', $property))->assertForbidden();
        $this->actingAs($staff)->post(route('settings.booking-website.nearby-places.store', $property), ['google_maps_url' => PANGKOR_JETTY])->assertForbidden();
    });
});

describe('suggestions', function () {
    it('lists Approved places within 25 km, nearest first, at most 10, without places the property has', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner(4.2105, 100.5535);
        $near = NearbyPlace::factory()->at(4.2305, 100.5535)->create(['name' => 'Near']);
        $nearer = NearbyPlace::factory()->at(4.2115, 100.5535)->create(['name' => 'Nearer']);
        NearbyPlace::factory()->at(5.5, 100.5535)->create(['name' => 'Far']);
        NearbyPlace::factory()->at(4.2106, 100.5535)->status(NearbyPlaceStatus::Pending)->create(['name' => 'Pending']);
        $own = NearbyPlace::factory()->at(4.2106, 100.5535)->create(['name' => 'Own']);
        $property->nearbyPlaces()->attach($own->id, ['position' => 1]);

        $suggestions = app(NearbyPlaceSuggestions::class)->for($property);

        expect($suggestions->pluck('name')->all())->toBe(['Nearer', 'Near']);

        $this->actingAs($owner)->get(route('settings.booking-website.nearby-places', $property))
            ->assertInertia(fn (Assert $page) => $page->where('suggestions.0.name', 'Nearer')->where('suggestions.0.distance', '0.1 km')->where('suggestions.1.distance', '2.2 km'));
    });

    it('caps the list at 10 and is empty without a property location', function () {
        ['property' => $property] = nearbyOwner(4.2105, 100.5535);
        NearbyPlace::factory()->count(12)->at(4.2106, 100.5535)->create();

        expect(app(NearbyPlaceSuggestions::class)->for($property))->toHaveCount(10);

        $property->forceFill(['latitude' => null, 'longitude' => null]);

        expect(app(NearbyPlaceSuggestions::class)->for($property))->toBeEmpty();
    });

    it('adds a suggested place, and only an Approved one', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        $approved = NearbyPlace::factory()->create();
        $pending = NearbyPlace::factory()->status(NearbyPlaceStatus::Pending)->create();

        $this->actingAs($owner)->post(route('settings.booking-website.nearby-places.suggested', [$property, $approved]))->assertRedirect();
        $this->actingAs($owner)->post(route('settings.booking-website.nearby-places.suggested', [$property, $pending]))->assertRedirect();

        expect($property->nearbyPlaces()->pluck('nearby_places.id')->all())->toBe([$approved->id]);
    });
});

describe('admin review', function () {
    it('lists places Pending first with how many properties have them', function () {
        $pending = NearbyPlace::factory()->status(NearbyPlaceStatus::Pending)->create(['name' => 'Pending one']);
        NearbyPlace::factory()->create(['name' => 'Approved one']);
        ['property' => $property] = nearbyOwner();
        $property->nearbyPlaces()->attach($pending->id, ['position' => 1]);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.nearby-places.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/nearby-places/index')
                ->where('places.0.name', 'Pending one')
                ->where('places.0.propertiesCount', 1)
                ->where('places.0.canApprove', true)
                ->where('places.1.name', 'Approved one')
                ->where('places.1.canApprove', false));
    });

    it('opens the status tab from the URL and falls back to all', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.nearby-places.index', ['status' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page->where('status', 'pending'));
        $this->actingAs($admin)->get(route('admin.nearby-places.index', ['status' => 'unknown']))
            ->assertInertia(fn (Assert $page) => $page->where('status', 'all'));
    });

    it('adds a new place as Approved, linked to no property and suggested nearby', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.nearby-places.index', ['status' => 'pending']))
            ->post(route('admin.nearby-places.store'), ['google_maps_url' => PANGKOR_JETTY])
            ->assertRedirect(route('admin.nearby-places.index', ['status' => 'pending']));

        $place = NearbyPlace::query()->sole();

        expect($place->status)->toBe(NearbyPlaceStatus::Approved)
            ->and($place->created_by_property_id)->toBeNull()
            ->and($place->properties()->count())->toBe(0)
            ->and(app(NearbyPlaceSuggestions::class)->for($property)->pluck('id')->all())->toBe([$place->id])
            ->and(Activity::query()->where('event', 'nearby-place.created')->count())->toBe(1);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.nearby-places.index'))
            ->assertInertia(fn (Assert $page) => $page->where('places.0.addedBy', 'Admin'));
    });

    it('approves a Pending place when it is added again', function () {
        $mapsPlace = app(GoogleMapsLink::class)->parse(PANGKOR_JETTY);
        $place = NearbyPlace::factory()->status(NearbyPlaceStatus::Pending)->create(['fingerprint' => $mapsPlace->fingerprint]);

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.nearby-places.store'), ['google_maps_url' => PANGKOR_JETTY])
            ->assertInertiaFlash('toast.type', 'success');

        expect($place->refresh()->status)->toBe(NearbyPlaceStatus::Approved)
            ->and(NearbyPlace::query()->count())->toBe(1)
            ->and(Activity::query()->where('event', 'nearby-place.approved')->count())->toBe(1);
    });

    it('changes nothing when an Approved or Rejected place is added again', function (NearbyPlaceStatus $status) {
        $mapsPlace = app(GoogleMapsLink::class)->parse(PANGKOR_JETTY);
        $place = NearbyPlace::factory()->status($status)->create(['fingerprint' => $mapsPlace->fingerprint]);

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.nearby-places.store'), ['google_maps_url' => PANGKOR_JETTY])
            ->assertInertiaFlash('toast.type', 'error');

        expect($place->refresh()->status)->toBe($status)
            ->and(NearbyPlace::query()->count())->toBe(1)
            ->and(Activity::query()->whereIn('event', ['nearby-place.created', 'nearby-place.approved'])->exists())->toBeFalse();
    })->with([NearbyPlaceStatus::Approved, NearbyPlaceStatus::Rejected]);

    it('shows a validation error for a link it cannot read', function () {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.nearby-places.store'), ['google_maps_url' => 'https://www.google.com/maps/@4.2297,100.5535,17z'])
            ->assertSessionHasErrors('google_maps_url');

        expect(NearbyPlace::query()->exists())->toBeFalse();
    });

    it('renames a place for every property and keeps its fingerprint', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        $mapsPlace = app(GoogleMapsLink::class)->parse(PANGKOR_JETTY);
        $place = NearbyPlace::factory()->create(['name' => $mapsPlace->name, 'fingerprint' => $mapsPlace->fingerprint]);
        $property->nearbyPlaces()->attach($place->id, ['position' => 1]);
        $tab = route('admin.nearby-places.index', ['status' => 'approved']);

        $this->actingAs(User::factory()->admin()->create())->from($tab)
            ->put(route('admin.nearby-places.update', $place), ['name' => '  Pangkor Ferry Terminal '])
            ->assertRedirect($tab);

        expect($place->refresh()->name)->toBe('Pangkor Ferry Terminal')
            ->and($place->fingerprint)->toBe($mapsPlace->fingerprint)
            ->and(Activity::query()->where('event', 'nearby-place.renamed')->sole()->properties['old_name'])->toBe($mapsPlace->name);

        $this->actingAs($owner)->get(route('settings.booking-website.nearby-places', $property))
            ->assertInertia(fn (Assert $page) => $page->where('nearbyPlaces.0.name', 'Pangkor Ferry Terminal'));
    });

    it('finds a renamed place when its link is pasted again', function () {
        $mapsPlace = app(GoogleMapsLink::class)->parse(PANGKOR_JETTY);
        $place = NearbyPlace::factory()->create(['name' => 'Pangkor Ferry Terminal', 'fingerprint' => $mapsPlace->fingerprint]);
        ['owner' => $owner, 'property' => $property] = nearbyOwner();

        $this->actingAs($owner)->post(route('settings.booking-website.nearby-places.store', $property), ['google_maps_url' => PANGKOR_JETTY]);

        expect(NearbyPlace::query()->count())->toBe(1)
            ->and($property->nearbyPlaces()->sole()->name)->toBe($place->name);
    });

    it('refuses an empty name and logs nothing for the same name', function () {
        $place = NearbyPlace::factory()->create(['name' => 'Jetty']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.nearby-places.update', $place), ['name' => ' - '])->assertSessionHasErrors('name');
        $this->actingAs($admin)->put(route('admin.nearby-places.update', $place), ['name' => 'Jetty'])->assertSessionHasNoErrors();

        expect($place->refresh()->name)->toBe('Jetty')
            ->and(Activity::query()->where('event', 'nearby-place.renamed')->exists())->toBeFalse();
    });

    it('stays on the open tab after a review', function () {
        $place = NearbyPlace::factory()->status(NearbyPlaceStatus::Pending)->create();
        $tab = route('admin.nearby-places.index', ['status' => 'pending']);

        $this->actingAs(User::factory()->admin()->create())->from($tab)
            ->put(route('admin.nearby-places.approve', $place))->assertRedirect($tab);
    });

    it('approves a pending place', function () {
        $place = NearbyPlace::factory()->status(NearbyPlaceStatus::Pending)->create();

        $this->actingAs(User::factory()->admin()->create())->put(route('admin.nearby-places.approve', $place))->assertRedirect(route('admin.nearby-places.index'));

        expect($place->refresh()->status)->toBe(NearbyPlaceStatus::Approved)
            ->and(Activity::query()->where('event', 'nearby-place.approved')->count())->toBe(1);
    });

    it('rejects a place, removes it from every property and tells each owner', function () {
        Notification::fake();
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        ['owner' => $otherOwner, 'property' => $otherProperty] = nearbyOwner();
        $place = NearbyPlace::factory()->create(['name' => 'Pangkor Jetty']);
        $property->nearbyPlaces()->attach($place->id, ['position' => 1]);
        $otherProperty->nearbyPlaces()->attach($place->id, ['position' => 1]);

        $this->actingAs(User::factory()->admin()->create())->put(route('admin.nearby-places.reject', $place))->assertRedirect();

        expect($place->refresh()->status)->toBe(NearbyPlaceStatus::Rejected)
            ->and($place->properties()->count())->toBe(0);
        Notification::assertSentTo($owner, NearbyPlaceRemoved::class, fn (NearbyPlaceRemoved $notification) => $notification->toArray($owner)['body'] === 'Pangkor Jetty was removed from your nearby places.');
        Notification::assertSentTo($otherOwner, NearbyPlaceRemoved::class);
    });

    it('names the place in the notice as the owner knows it', function () {
        Notification::fake();
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        $place = NearbyPlace::factory()->create(['name' => 'Shared name']);
        $property->nearbyPlaces()->attach($place->id, ['position' => 1, 'display_name' => 'My name']);

        app(RejectNearbyPlace::class)(User::factory()->admin()->create(), $place);

        Notification::assertSentTo($owner, NearbyPlaceRemoved::class, fn (NearbyPlaceRemoved $notification) => $notification->toArray($owner)['body'] === 'My name was removed from your nearby places.');
    });

    it('stores the notice in the owner\'s in-app list', function () {
        ['owner' => $owner, 'property' => $property] = nearbyOwner();
        $place = NearbyPlace::factory()->create(['name' => 'Pangkor Jetty']);
        $property->nearbyPlaces()->attach($place->id, ['position' => 1]);

        app(RejectNearbyPlace::class)(User::factory()->admin()->create(), $place);

        expect($owner->notifications()->count())->toBe(1)
            ->and($owner->notifications()->first()->data['type'])->toBe('nearby_place_removed');
    });

    it('refuses a change the status does not allow', function () {
        $place = NearbyPlace::factory()->status(NearbyPlaceStatus::Rejected)->create();

        $this->actingAs(User::factory()->admin()->create())->put(route('admin.nearby-places.reject', $place))->assertRedirect();

        expect(Activity::query()->where('event', 'nearby-place.rejected')->exists())->toBeFalse();
    });

    it('is closed to property staff', function () {
        $place = NearbyPlace::factory()->status(NearbyPlaceStatus::Pending)->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('admin.nearby-places.index'))->assertNotFound();
        $this->actingAs($owner)->put(route('admin.nearby-places.approve', $place))->assertNotFound();
        $this->actingAs($owner)->post(route('admin.nearby-places.store'), ['google_maps_url' => PANGKOR_JETTY])->assertNotFound();
        $this->actingAs($owner)->put(route('admin.nearby-places.update', $place), ['name' => 'Renamed'])->assertNotFound();

        expect($place->refresh()->status)->toBe(NearbyPlaceStatus::Pending)
            ->and(NearbyPlace::query()->count())->toBe(1);
    });
});
