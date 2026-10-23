<?php

use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function moveRoute(Property $property): string
{
    return route('admin.properties.partner.update', $property->code);
}

test('a direct property moves to an Active partner and gets a code with its prefix', function () {
    $partner = Partner::factory()->create();
    $property = Property::factory()->create();
    $oldCode = $property->code;
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->put(moveRoute($property), ['partner_id' => $partner->id])
        ->assertRedirect(route('admin.properties.show', $partner->code_prefix.'1101'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Property moved to {$partner->name}."]);

    expect($property->refresh()->partner_id)->toBe($partner->id)
        ->and($property->code)->toBe($partner->code_prefix.'1101');

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('property.partner-moved')
        ->and($activity->causer->is($admin))->toBeTrue()
        ->and($activity->subject->is($property))->toBeTrue()
        ->and($activity->properties->all())->toBe(['from' => null, 'to' => ['id' => $partner->id, 'name' => $partner->name], 'code' => ['from' => $oldCode, 'to' => $partner->code_prefix.'1101']]);
});

test('a partner property moves to direct, gets an ES code and the old partner loses view as', function () {
    $partnerUser = User::factory()->partner()->create();
    $partner = $partnerUser->partner;
    $property = Property::factory()->create(['partner_id' => $partner->id, 'code' => $partner->code_prefix.'1101']);
    $oldCode = $property->code;

    $this->actingAs($partnerUser)->get(route('view-as.show', $oldCode))->assertOk();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(moveRoute($property), ['partner_id' => null])
        ->assertRedirect(route('admin.properties.show', 'ES1101'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Property moved to direct.']);

    expect($property->refresh()->partner_id)->toBeNull()
        ->and($property->code)->toBe('ES1101');

    $activity = Activity::query()->sole();

    expect($activity->properties->all())->toBe(['from' => ['id' => $partner->id, 'name' => $partner->name], 'to' => null, 'code' => ['from' => $oldCode, 'to' => 'ES1101']]);

    $this->actingAs($partnerUser)->get(route('view-as.show', $property->code))->assertNotFound();
    $this->get(route('view-as.show', $oldCode))->assertNotFound();
});

test('a move from one partner to another is refused', function () {
    $old = Partner::factory()->create();
    $new = Partner::factory()->create();
    $property = Property::factory()->create(['partner_id' => $old->id]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(moveRoute($property), ['partner_id' => $new->id])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A property can move between direct and an Active partner only.']);

    $code = $property->code;

    expect($property->refresh()->partner_id)->toBe($old->id)
        ->and($property->code)->toBe($code)
        ->and(Activity::query()->count())->toBe(0);
});

test('a direct property cannot move to a partner that is not Active', function (string $state) {
    $partner = Partner::factory()->{$state}()->create();
    $property = Property::factory()->create();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(moveRoute($property), ['partner_id' => $partner->id])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A property can move between direct and an Active partner only.']);

    expect($property->refresh()->partner_id)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
})->with(['suspended', 'invited']);

test('a property of a suspended partner can still move to direct', function () {
    $property = Property::factory()->create(['partner_id' => Partner::factory()->suspended()->create()->id]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(moveRoute($property), ['partner_id' => null])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Property moved to direct.']);

    expect($property->refresh()->partner_id)->toBeNull();
});

test('moving to where the property already is changes and logs nothing', function () {
    $partner = Partner::factory()->create();
    $direct = Property::factory()->create();
    $owned = Property::factory()->create(['partner_id' => $partner->id]);
    $codes = [$direct->code, $owned->code];

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(moveRoute($direct), ['partner_id' => null])
        ->assertRedirect();
    $this->put(moveRoute($owned), ['partner_id' => $partner->id])->assertRedirect();

    expect($owned->refresh()->partner_id)->toBe($partner->id)
        ->and([$direct->refresh()->code, $owned->code])->toBe($codes)
        ->and(Activity::query()->count())->toBe(0);
});

test('an unknown partner fails validation', function () {
    $property = Property::factory()->create();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(moveRoute($property), ['partner_id' => 999999])
        ->assertSessionHasErrors('partner_id');
});

test('only admins can move a property', function () {
    $partnerUser = User::factory()->partner()->create();
    $property = Property::factory()->create(['partner_id' => $partnerUser->partner_id]);
    $staff = User::factory()->for(Property::factory()->onboarding())->create();

    $this->actingAs($partnerUser)->put(moveRoute($property), ['partner_id' => null])->assertNotFound();
    $this->actingAs($staff)->put(moveRoute($property), ['partner_id' => null])->assertNotFound();
    auth()->logout();
    $this->put(moveRoute($property), ['partner_id' => null])->assertRedirect(route('login'));

    expect($property->refresh()->partner_id)->toBe($partnerUser->partner_id)
        ->and(Activity::query()->count())->toBe(0);
});

test('two-factor is required to move a property', function () {
    config(['estatell.support_require_two_factor' => true]);
    $property = Property::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(moveRoute($property), ['partner_id' => Partner::factory()->create()->id])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect($property->refresh()->partner_id)->toBeNull();
});

test('the property page offers Active partners for a direct property and none for a partner property', function () {
    $active = Partner::factory()->create();
    Partner::factory()->suspended()->create();
    $direct = Property::factory()->create();
    $owned = Property::factory()->create(['partner_id' => $active->id]);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->get(route('admin.properties.show', $direct->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('partnerOptions', 1)
            ->where('partnerOptions.0.value', (string) $active->id)
            ->where('property.partnerName', null));

    $this->get(route('admin.properties.show', $owned->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('partnerOptions', 0)
            ->where('property.partnerName', $active->name));
});

test('each move takes the next number of the new prefix and a refused move uses none', function () {
    $partner = Partner::factory()->create();
    $first = Property::factory()->create();
    $second = Property::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->put(moveRoute($first), ['partner_id' => $partner->id]);
    $this->put(moveRoute($second), ['partner_id' => Partner::factory()->suspended()->create()->id]);
    $this->put(moveRoute($second), ['partner_id' => $partner->id]);

    expect($first->refresh()->code)->toBe($partner->code_prefix.'1101')
        ->and($second->refresh()->code)->toBe($partner->code_prefix.'1102');
});
