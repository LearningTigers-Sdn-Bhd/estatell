<?php

use App\Enums\AmenityType;
use App\Enums\PermissionEnum;
use App\Features\BookingEngine;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\AmenitySeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{owner: User, property: Property}
 */
function amenityOwner(): array
{
    $owner = User::factory()->owner()->create();
    Feature::for($owner->property)->activate(BookingEngine::class);

    return ['owner' => $owner, 'property' => $owner->property];
}

test('the seeder loads the wastays list once and keeps admin changes on a second run', function () {
    $this->seed(AmenitySeeder::class);

    $count = Amenity::query()->count();
    $wifi = Amenity::query()->where('name', '24-hour front desk')->firstOrFail();
    $wifi->update(['active' => false, 'position' => 9999]);

    $this->seed(AmenitySeeder::class);

    expect($count)->toBeGreaterThan(400)
        ->and(Amenity::query()->count())->toBe($count)
        ->and($wifi->refresh()->active)->toBeFalse()
        ->and($wifi->position)->toBe(9999);
});

test('admins see every amenity in list order', function () {
    Amenity::factory()->create(['name' => 'Pool']);
    Amenity::factory()->room()->create(['name' => 'Balcony']);
    Amenity::factory()->inactive()->create(['name' => 'Sauna']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.amenities.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/amenities/index')
            ->has('amenities', 3)
            ->where('amenities.0.name', 'Pool')
            ->where('amenities.1.type', 'room')
            ->where('amenities.2.active', false)
            ->has('typeOptions', 2)
            ->where('type', 'all'));
});

test('the type tab comes from the URL and falls back to all', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.amenities.index', ['type' => 'room']))
        ->assertInertia(fn (Assert $page) => $page->where('type', 'room'));
    $this->actingAs($admin)->get(route('admin.amenities.index', ['type' => 'nonsense']))
        ->assertInertia(fn (Assert $page) => $page->where('type', 'all'));
});

test('property staff cannot open the admin list', function () {
    $this->actingAs(User::factory()->owner()->create())->get(route('admin.amenities.index'))->assertNotFound();
});

test('writes need two factor', function () {
    config(['estatell.support_require_two_factor' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.amenities.store'), ['name' => 'Pool', 'type' => 'property', 'category' => 'Outdoors'])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect(Amenity::query()->count())->toBe(0);
});

test('adding an amenity puts it last and logs it', function () {
    Amenity::factory()->create(['name' => 'Pool']);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->post(route('admin.amenities.store'), ['name' => '  Gym ', 'type' => 'property', 'category' => 'Activities'])
        ->assertRedirect(route('admin.amenities.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Gym added.']);

    $gym = Amenity::query()->where('name', 'Gym')->firstOrFail();

    expect($gym->position)->toBe(2)->and($gym->active)->toBeTrue()->and($gym->type)->toBe(AmenityType::Property);
    expect(Activity::query()->where('event', 'amenity.created')->count())->toBe(1);
});

test('a name already used by the same type is refused, but another type may reuse it', function () {
    Amenity::factory()->create(['name' => 'Heating']);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->post(route('admin.amenities.store'), ['name' => 'heating', 'type' => 'property', 'category' => 'General'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'An amenity with this name already exists for this type.']);

    $this->actingAs($admin)
        ->post(route('admin.amenities.store'), ['name' => 'Heating', 'type' => 'room', 'category' => 'In Room'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Heating added.']);

    expect(Amenity::query()->count())->toBe(2);
});

test('editing changes the name and category but never the type', function () {
    $amenity = Amenity::factory()->room()->create(['name' => 'Balkony', 'category' => 'Outside']);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->put(route('admin.amenities.update', $amenity), ['name' => 'Balcony', 'category' => 'View', 'type' => 'property'])
        ->assertSessionHasErrors('type');

    $this->actingAs($admin)
        ->put(route('admin.amenities.update', $amenity), ['name' => 'Balcony', 'category' => 'View'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Amenity saved.']);

    expect($amenity->refresh()->name)->toBe('Balcony')->and($amenity->category)->toBe('View')->and($amenity->type)->toBe(AmenityType::Room);
    expect(Activity::query()->where('event', 'amenity.updated')->count())->toBe(1);
});

test('retiring and bringing back an amenity logs once and refuses a repeat', function () {
    $amenity = Amenity::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->put(route('admin.amenities.active.update', $amenity), ['active' => false])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Amenity saved.']);
    $this->actingAs($admin)->put(route('admin.amenities.active.update', $amenity), ['active' => false])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'That change did not apply. Reload the page and try again.']);

    expect($amenity->refresh()->active)->toBeFalse();
    expect(Activity::query()->where('event', 'amenity.retired')->count())->toBe(1);
});

test('reordering needs exactly the full list', function () {
    [$first, $second, $third] = Amenity::factory()->count(3)->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->put(route('admin.amenities.reorder'), ['order' => [$third->id, $first->id, $second->id]]);

    expect($third->refresh()->position)->toBe(1)->and($first->refresh()->position)->toBe(2)->and($second->refresh()->position)->toBe(3);

    $this->actingAs($admin)->put(route('admin.amenities.reorder'), ['order' => [$first->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The amenities changed. Reload the page and try again.']);

    expect($third->refresh()->position)->toBe(1);
});

test('the Amenities tab lists active property amenities plus a retired one that is ticked', function () {
    ['owner' => $owner, 'property' => $property] = amenityOwner();
    $pool = Amenity::factory()->create(['name' => 'Pool']);
    $sauna = Amenity::factory()->inactive()->create(['name' => 'Sauna']);
    Amenity::factory()->inactive()->create(['name' => 'Bowling']);
    Amenity::factory()->room()->create(['name' => 'Balcony']);
    $property->amenities()->attach([$sauna->id]);

    $this->actingAs($owner)->get(route('settings.booking-website.amenities', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/booking-website/amenities')
            ->has('amenities.amenities', 2)
            ->where('amenities.amenities.0.name', 'Pool')
            ->where('amenities.amenities.1.name', 'Sauna')
            ->where('amenities.selectedIds', [$sauna->id]));

    expect($pool->id)->toBeInt();
});

test('saving the Amenities tab replaces the ticked amenities and logs the change', function () {
    ['owner' => $owner, 'property' => $property] = amenityOwner();
    [$pool, $gym] = Amenity::factory()->count(2)->create();
    $property->amenities()->attach($pool);

    $this->actingAs($owner)
        ->put(route('settings.booking-website.amenities.update', $property), ['amenity_ids' => [$gym->id]])
        ->assertRedirect(route('settings.booking-website.amenities', $property))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Amenities saved.']);

    expect($property->amenities()->pluck('amenities.id')->all())->toBe([$gym->id]);
    expect(Activity::query()->where('event', 'property.amenities_changed')->count())->toBe(1);

    $this->actingAs($owner)->put(route('settings.booking-website.amenities.update', $property), ['amenity_ids' => []]);

    expect($property->amenities()->count())->toBe(0);
});

test('the Amenities tab refuses room amenities, retired ones not yet ticked and unknown ids', function () {
    ['owner' => $owner, 'property' => $property] = amenityOwner();
    $room = Amenity::factory()->room()->create();
    $retired = Amenity::factory()->inactive()->create();

    foreach ([[$room->id], [$retired->id], [999999]] as $ids) {
        $this->actingAs($owner)
            ->put(route('settings.booking-website.amenities.update', $property), ['amenity_ids' => $ids])
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The amenity list changed. Reload the page and try again.']);
    }

    expect($property->amenities()->count())->toBe(0);
});

test('the Amenities tab needs the manage permission and the feature', function () {
    ['property' => $property] = amenityOwner();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::SettingsManage])->create();

    $this->actingAs($staff)->get(route('settings.booking-website.amenities', $property))->assertForbidden();
    $this->actingAs($staff)->put(route('settings.booking-website.amenities.update', $property), ['amenity_ids' => []])->assertForbidden();

    Feature::for($property)->deactivate(BookingEngine::class);
    $this->actingAs(User::factory()->owner()->create())->get(route('settings.booking-website.amenities', $property))->assertNotFound();
});

test('room amenities save on the room type page and stay inside the property', function () {
    ['owner' => $owner, 'property' => $property] = amenityOwner();
    $roomType = RoomType::factory()->for($property)->create();
    $balcony = Amenity::factory()->room()->create(['name' => 'Balcony']);
    $pool = Amenity::factory()->create(['name' => 'Pool']);

    $this->actingAs($owner)->get(route('settings.room-types.amenities.edit', [$property, $roomType]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/room-types/amenities')
            ->has('amenities.amenities', 1)
            ->where('amenities.amenities.0.name', 'Balcony')
            ->where('canManage', true));

    $this->actingAs($owner)
        ->put(route('settings.room-types.amenities.update', [$property, $roomType]), ['amenity_ids' => [$balcony->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Amenities saved.']);

    expect($roomType->amenities()->pluck('amenities.id')->all())->toBe([$balcony->id]);
    expect(Activity::query()->where('event', 'room_type.amenities_changed')->count())->toBe(1);

    $this->actingAs($owner)
        ->put(route('settings.room-types.amenities.update', [$property, $roomType]), ['amenity_ids' => [$pool->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The amenity list changed, or the room type is archived. Reload the page and try again.']);

    $other = RoomType::factory()->for(Property::factory()->create())->create();
    $this->actingAs($owner)->get(route('settings.room-types.amenities.edit', [$property, $other]))->assertNotFound();
});

test('an archived room type refuses amenity changes and view-only staff cannot save', function () {
    ['owner' => $owner, 'property' => $property] = amenityOwner();
    $roomType = RoomType::factory()->for($property)->create(['archived_at' => now()]);
    $balcony = Amenity::factory()->room()->create();

    $this->actingAs($owner)
        ->put(route('settings.room-types.amenities.update', [$property, $roomType]), ['amenity_ids' => [$balcony->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The amenity list changed, or the room type is archived. Reload the page and try again.']);

    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::RoomsView])->create();
    $active = RoomType::factory()->for($property)->create();

    $this->actingAs($viewer)->get(route('settings.room-types.amenities.edit', [$property, $active]))->assertOk();
    $this->actingAs($viewer)
        ->put(route('settings.room-types.amenities.update', [$property, $active]), ['amenity_ids' => [$balcony->id]])
        ->assertForbidden();
});
