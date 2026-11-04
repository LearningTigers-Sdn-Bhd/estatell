<?php

use App\Enums\AmenityType;
use App\Enums\PermissionEnum;
use App\Features\BookingEngine;
use App\Models\Amenity;
use App\Models\AmenityCategory;
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
    $categoryCount = AmenityCategory::query()->count();
    $wifi = Amenity::query()->where('name', '24-hour front desk')->firstOrFail();
    $wifi->update(['active' => false, 'position' => 9999]);
    $wifi->category()->firstOrFail()->update(['name' => 'Renamed by admin']);

    $this->seed(AmenitySeeder::class);

    expect($count)->toBeGreaterThan(300)
        ->and(Amenity::query()->count())->toBe($count)
        ->and(AmenityCategory::query()->count())->toBe($categoryCount)
        ->and($wifi->refresh()->active)->toBeFalse()
        ->and($wifi->position)->toBe(9999)
        ->and($wifi->category->name)->toBe('Renamed by admin');
});

test('admins see every amenity by category order, then by position inside it', function () {
    $outdoors = AmenityCategory::factory()->create(['name' => 'Outdoors', 'position' => 2]);
    $general = AmenityCategory::factory()->create(['name' => 'General', 'position' => 1]);
    Amenity::factory()->inCategory($outdoors)->create(['name' => 'Pool', 'position' => 1]);
    Amenity::factory()->inCategory($general)->inactive()->create(['name' => 'Sauna', 'position' => 3]);
    Amenity::factory()->inCategory($general)->create(['name' => 'Lift', 'position' => 2]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.amenities.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/amenities/index')
            ->has('amenities', 3)
            ->where('amenities.0.name', 'Lift')
            ->where('amenities.0.category', 'General')
            ->where('amenities.1.name', 'Sauna')
            ->where('amenities.1.active', false)
            ->where('amenities.2.name', 'Pool')
            ->has('categories', 2)
            ->where('categories.0.name', 'General')
            ->where('categories.0.amenitiesCount', 2)
            ->has('typeOptions', 2)
            ->where('tab', 'property'));
});

test('the open tab comes from the URL and falls back to property', function () {
    $admin = User::factory()->admin()->create();

    foreach (['room' => 'room', 'categories' => 'categories', 'nonsense' => 'property'] as $tab => $expected) {
        $this->actingAs($admin)->get(route('admin.amenities.index', ['tab' => $tab]))
            ->assertInertia(fn (Assert $page) => $page->where('tab', $expected));
    }
});

test('the list page comes from the URL and is never below one', function () {
    $admin = User::factory()->admin()->create();

    foreach ([2 => 2, 0 => 1, -3 => 1] as $page => $expected) {
        $this->actingAs($admin)->get(route('admin.amenities.index', ['tab' => 'room', 'page' => $page]))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('page', $expected)
                ->where('listUrl', route('admin.amenities.index', ['tab' => 'room'])));
    }
});

test('property staff cannot open the admin list', function () {
    $this->actingAs(User::factory()->owner()->create())->get(route('admin.amenities.index'))->assertNotFound();
});

test('writes need two factor', function () {
    config(['estatell.support_require_two_factor' => true]);

    $category = AmenityCategory::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.amenities.store'), ['name' => 'Pool', 'amenity_category_id' => $category->id])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);
    $this->actingAs($admin)
        ->delete(route('admin.amenities.categories.destroy', $category))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect(Amenity::query()->count())->toBe(0)->and($category->fresh())->not->toBeNull();
});

test('adding an amenity takes the type from its category, stays on the open tab and logs it', function () {
    Amenity::factory()->create(['name' => 'Pool']);
    $category = AmenityCategory::factory()->room()->create(['name' => 'Bathroom']);
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $openTab = route('admin.amenities.index', ['tab' => 'room']);

    $this->actingAs($admin)->from($openTab)
        ->post(route('admin.amenities.store'), ['name' => '  Bathtub ', 'amenity_category_id' => $category->id])
        ->assertRedirect($openTab)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Bathtub added.']);

    $bathtub = Amenity::query()->where('name', 'Bathtub')->firstOrFail();

    expect($bathtub->position)->toBe(2)
        ->and($bathtub->active)->toBeTrue()
        ->and($bathtub->type)->toBe(AmenityType::Room)
        ->and($bathtub->amenity_category_id)->toBe($category->id);
    expect(Activity::query()->where('event', 'amenity.created')->count())->toBe(1);
});

test('adding an amenity needs a category that exists', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    foreach ([[], ['amenity_category_id' => 999999]] as $category) {
        $this->actingAs($admin)
            ->post(route('admin.amenities.store'), ['name' => 'Pool', ...$category])
            ->assertSessionHasErrors('amenity_category_id');
    }

    expect(Amenity::query()->count())->toBe(0);
});

test('a name already used by the same type is refused, but another type may reuse it', function () {
    Amenity::factory()->create(['name' => 'Heating']);
    $property = AmenityCategory::factory()->create();
    $room = AmenityCategory::factory()->room()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->post(route('admin.amenities.store'), ['name' => 'heating', 'amenity_category_id' => $property->id])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'An amenity with this name already exists for this type.']);

    $this->actingAs($admin)
        ->post(route('admin.amenities.store'), ['name' => 'Heating', 'amenity_category_id' => $room->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Heating added.']);

    expect(Amenity::query()->count())->toBe(2);
});

test('editing changes the name and category but refuses a category of the other type', function () {
    $outside = AmenityCategory::factory()->room()->create(['name' => 'Outside']);
    $view = AmenityCategory::factory()->room()->create(['name' => 'View']);
    $propertyCategory = AmenityCategory::factory()->create();
    $amenity = Amenity::factory()->inCategory($outside)->create(['name' => 'Balkony']);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->put(route('admin.amenities.update', $amenity), ['name' => 'Balcony', 'amenity_category_id' => $propertyCategory->id])
        ->assertSessionHasErrors('amenity_category_id');

    expect($amenity->refresh()->amenity_category_id)->toBe($outside->id);

    $this->actingAs($admin)
        ->put(route('admin.amenities.update', $amenity), ['name' => 'Balcony', 'amenity_category_id' => $view->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Amenity saved.']);

    expect($amenity->refresh()->name)->toBe('Balcony')
        ->and($amenity->amenity_category_id)->toBe($view->id)
        ->and($amenity->type)->toBe(AmenityType::Room);
    expect(Activity::query()->where('event', 'amenity.updated')->count())->toBe(1);
});

test('adding a category puts it last, and a name is unique per type ignoring case', function () {
    AmenityCategory::factory()->create(['name' => 'General']);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->post(route('admin.amenities.categories.store'), ['name' => 'general', 'type' => 'property'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A category with this name already exists for this type.']);

    $this->actingAs($admin)
        ->post(route('admin.amenities.categories.store'), ['name' => ' General ', 'type' => 'room'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'General added.']);

    $room = AmenityCategory::query()->where('type', 'room')->firstOrFail();

    expect(AmenityCategory::query()->count())->toBe(2)->and($room->position)->toBe(2);
    expect(Activity::query()->where('event', 'amenity_category.created')->count())->toBe(1);
});

test('renaming a category moves every amenity with it and never changes the type', function () {
    $category = AmenityCategory::factory()->create(['name' => 'Food And Drink']);
    AmenityCategory::factory()->create(['name' => 'General']);
    $amenity = Amenity::factory()->inCategory($category)->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->put(route('admin.amenities.categories.update', $category), ['name' => 'Dining', 'type' => 'room'])
        ->assertSessionHasErrors('type');
    $this->actingAs($admin)
        ->put(route('admin.amenities.categories.update', $category), ['name' => 'general'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A category with this name already exists for this type.']);

    expect($category->refresh()->name)->toBe('Food And Drink');

    $this->actingAs($admin)
        ->put(route('admin.amenities.categories.update', $category), ['name' => 'Dining'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Category saved.']);

    expect($amenity->refresh()->category->name)->toBe('Dining');
    expect(Activity::query()->where('event', 'amenity_category.updated')->count())->toBe(1);
});

test('a category can only be deleted when no amenity uses it, retired ones included', function () {
    $category = AmenityCategory::factory()->create(['name' => 'Pets']);
    $amenity = Amenity::factory()->inCategory($category)->inactive()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->delete(route('admin.amenities.categories.destroy', $category))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Pets still has amenities. Move them first.']);

    expect($category->fresh())->not->toBeNull();
    expect(Activity::query()->where('event', 'amenity_category.deleted')->count())->toBe(0);

    $amenity->delete();

    $this->actingAs($admin)
        ->delete(route('admin.amenities.categories.destroy', $category))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Pets deleted.']);

    expect($category->fresh())->toBeNull();
    expect(Activity::query()->where('event', 'amenity_category.deleted')->count())->toBe(1);
});

test('reordering categories needs exactly the full list and changes the picker order', function () {
    ['owner' => $owner, 'property' => $property] = amenityOwner();
    $first = AmenityCategory::factory()->create(['name' => 'First']);
    $second = AmenityCategory::factory()->create(['name' => 'Second']);
    Amenity::factory()->inCategory($first)->create(['name' => 'Pool']);
    Amenity::factory()->inCategory($second)->create(['name' => 'Gym']);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->put(route('admin.amenities.categories.reorder'), ['order' => [$first->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The categories changed. Reload the page and try again.']);

    expect($first->refresh()->position)->toBe(1);

    $this->actingAs($admin)->put(route('admin.amenities.categories.reorder'), ['order' => [$second->id, $first->id]]);

    $this->actingAs($owner)->get(route('settings.booking-website.amenities', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('amenities.amenities.0.name', 'Gym')
            ->where('amenities.amenities.0.category', 'Second')
            ->where('amenities.amenities.1.name', 'Pool'));
});

test('property staff cannot change categories', function () {
    $category = AmenityCategory::factory()->create();

    $this->actingAs(User::factory()->owner()->create())
        ->delete(route('admin.amenities.categories.destroy', $category))
        ->assertNotFound();

    expect($category->fresh())->not->toBeNull();
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
