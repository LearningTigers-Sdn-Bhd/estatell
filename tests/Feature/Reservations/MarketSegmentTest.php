<?php

use App\Enums\DefaultRoleEnum;
use App\Models\MarketSegment;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function marketSegmentsUrl(Property $property, ?MarketSegment $segment = null): string
{
    return $segment === null
        ? route('settings.market-segments.index', ['property' => $property->code])
        : route('settings.market-segments.update', ['property' => $property->code, 'marketSegment' => $segment->id]);
}

test('the page lists the active segments first, then by name', function () {
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    MarketSegment::factory()->for($user->property)->create(['name' => 'Leisure']);
    MarketSegment::factory()->for($user->property)->inactive()->create(['name' => 'Crew']);
    MarketSegment::factory()->for($user->property)->create(['name' => 'Corporate']);

    $this->actingAs($user)->get(marketSegmentsUrl($user->property))->assertInertia(fn (Assert $page) => $page
        ->component('settings/market-segments/index')
        ->where('segments', fn ($segments) => collect($segments)->pluck('name')->all() === ['Corporate', 'Leisure', 'Crew']));
});

test('staff add a segment, and a name taken ignoring case is refused', function () {
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    MarketSegment::factory()->for($user->property)->create(['name' => 'Leisure']);

    $this->actingAs($user)->post(marketSegmentsUrl($user->property), ['name' => ' leisure '])
        ->assertSessionHasErrors(['name' => 'Another market segment already uses this name.']);

    $this->post(marketSegmentsUrl($user->property), ['name' => ' Corporate '])
        ->assertRedirect(marketSegmentsUrl($user->property))
        ->assertInertiaFlash('toast.message', 'Corporate added.');

    expect($user->property->marketSegments()->where('name', 'Corporate')->sole()->active)->toBeTrue()
        ->and(Activity::query()->where('event', 'market_segment.created')->count())->toBe(1);
});

test('staff rename a segment and turn it off', function () {
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $segment = MarketSegment::factory()->for($user->property)->create(['name' => 'Leisure']);

    $this->actingAs($user)->put(marketSegmentsUrl($user->property, $segment), ['name' => 'Leisure FIT', 'active' => false])
        ->assertSessionHasNoErrors();

    expect($segment->fresh()->only(['name', 'active']))->toBe(['name' => 'Leisure FIT', 'active' => false])
        ->and(Activity::query()->where('event', 'market_segment.updated')->sole()->properties['changed'])->toBe(['name', 'active']);
});

test('settings need settings.manage, and another property\'s segment is not found', function () {
    $front = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $other = MarketSegment::factory()->create();

    $this->actingAs($front)->post(marketSegmentsUrl($front->property), ['name' => 'Leisure'])->assertForbidden();
    $this->actingAs($owner)->put(marketSegmentsUrl($owner->property, $other), ['name' => 'Taken'])->assertNotFound();

    expect(MarketSegment::query()->pluck('name')->all())->toBe([$other->name]);
});
