<?php

use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\RecommendedItemDismissal;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('a settings manager marks an item as not needed, and it is logged', function () {
    $owner = User::factory()->owner()->for(Property::factory()->live())->create();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('settings.recommended-items.dismissals.store', $property->code), ['item' => 'room_groups'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Marked as not needed.']);

    $dismissal = RecommendedItemDismissal::query()->sole();

    expect($dismissal->property_id)->toBe($property->id)
        ->and($dismissal->item)->toBe('room_groups')
        ->and($dismissal->dismissed_by)->toBe($owner->id);

    expect(Activity::query()->where('event', 'onboarding.recommended-item-dismissed')->count())->toBe(1);
});

test('the logo, an unknown key and a required section are refused and write nothing', function (string $item) {
    $owner = User::factory()->owner()->for(Property::factory()->live())->create();

    $this->actingAs($owner)
        ->post(route('settings.recommended-items.dismissals.store', $owner->property->code), ['item' => $item])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This item cannot be marked as not needed.']);

    expect(RecommendedItemDismissal::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with(['logo', 'bogus', 'skipped:rooms']);

test('marking the same item twice writes once', function () {
    $owner = User::factory()->owner()->for(Property::factory()->live())->create();
    $url = route('settings.recommended-items.dismissals.store', $owner->property->code);

    $this->actingAs($owner)->post($url, ['item' => 'missed_arrivals']);
    $this->actingAs($owner)
        ->post($url, ['item' => 'missed_arrivals'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This item cannot be marked as not needed.']);

    expect(RecommendedItemDismissal::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(1);
});

test('the item key is required', function () {
    $owner = User::factory()->owner()->for(Property::factory()->live())->create();

    $this->actingAs($owner)
        ->post(route('settings.recommended-items.dismissals.store', $owner->property->code), [])
        ->assertSessionHasErrors('item');

    expect(RecommendedItemDismissal::query()->count())->toBe(0);
});

test('marking an item as not needed needs settings.manage', function () {
    $property = Property::factory()->live()->create();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::StaffManage])->create();

    $this->actingAs($staff)
        ->post(route('settings.recommended-items.dismissals.store', $property->code), ['item' => 'room_groups'])
        ->assertForbidden();

    expect(RecommendedItemDismissal::query()->count())->toBe(0);
});
