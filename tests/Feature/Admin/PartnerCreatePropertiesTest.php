<?php

use App\Actions\Platform\CreatePartner;
use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->withTwoFactor()->create();
});

function updateCanCreate(Partner $partner): string
{
    return route('admin.partners.can-create-properties.update', $partner);
}

test('every way of adding a partner leaves the switch off', function () {
    $partner = DB::transaction(fn () => app(CreatePartner::class)('Andaman Stays', 'AS', PartnerStatus::Active));

    expect($partner->refresh()->can_create_properties)->toBeFalse()
        ->and(Partner::factory()->create()->refresh()->can_create_properties)->toBeFalse()
        ->and(Partner::factory()->canCreateProperties()->create()->can_create_properties)->toBeTrue();
});

test('the admin turns the switch on and off and each change is logged', function () {
    $partner = Partner::factory()->create();

    $this->actingAs($this->admin)
        ->put(updateCanCreate($partner), ['allowed' => true])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "{$partner->name} can create properties."]);

    expect($partner->refresh()->can_create_properties)->toBeTrue();

    $this->put(updateCanCreate($partner), ['allowed' => false])->assertRedirect();

    $events = Activity::query()->orderBy('id')->get();

    expect($partner->refresh()->can_create_properties)->toBeFalse()
        ->and($events->pluck('event')->all())->toBe(['partner.create-properties-allowed', 'partner.create-properties-disallowed'])
        ->and($events->every(fn (Activity $activity) => $activity->causer_id === $this->admin->id && $activity->subject_id === $partner->id))->toBeTrue();
});

test('setting the same value again does nothing and logs nothing', function () {
    $partner = Partner::factory()->canCreateProperties()->create();

    $this->actingAs($this->admin)->put(updateCanCreate($partner), ['allowed' => true])->assertRedirect();

    expect($partner->refresh()->can_create_properties)->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});

test('only an Active partner can be changed', function (Closure $makePartner) {
    $partner = $makePartner();

    $this->actingAs($this->admin)
        ->put(updateCanCreate($partner), ['allowed' => true])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only an Active partner can be changed.']);

    expect($partner->refresh()->can_create_properties)->toBeFalse()
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'invited' => [fn () => Partner::factory()->invited()->create()],
    'suspended' => [fn () => Partner::factory()->suspended()->create()],
]);

test('suspending keeps the switch value', function () {
    $partner = Partner::factory()->canCreateProperties()->create();

    $this->actingAs($this->admin)->post(route('admin.partners.suspend', $partner));

    expect($partner->refresh()->can_create_properties)->toBeTrue();
});

test('the switch needs an allowed value', function () {
    $partner = Partner::factory()->create();

    $this->actingAs($this->admin)->put(updateCanCreate($partner), [])->assertSessionHasErrors('allowed');
});

test('the switch is admin only and needs two-factor', function () {
    $partner = Partner::factory()->create();

    $this->actingAs(User::factory()->create())->put(updateCanCreate($partner), ['allowed' => true])->assertNotFound();
    $this->actingAs(User::factory()->partner($partner)->create())->put(updateCanCreate($partner), ['allowed' => true])->assertNotFound();

    config(['estatell.support_require_two_factor' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(updateCanCreate($partner), ['allowed' => true])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect($partner->refresh()->can_create_properties)->toBeFalse();
});

test('the Active tab rows carry the switch and the signup link', function () {
    $partner = Partner::factory()->canCreateProperties()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.partners.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('partners.data.0.canCreateProperties', true)
            ->where('partners.data.0.signupUrl', route('join', $partner->signup_code))
        );
});
