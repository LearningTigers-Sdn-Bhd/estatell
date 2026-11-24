<?php

use App\Actions\Platform\SetPropertyFeature;
use App\Enums\PlatformFeature;
use App\Features\NativePos;
use App\Features\PosIntegration;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\OpenPosShifts;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/NativePosSetup.php';

it('is off by default and turns on and off from the platform feature action', function () {
    $property = Property::factory()->live()->create();
    $admin = User::factory()->admin()->create();

    expect(Feature::for($property)->active(NativePos::class))->toBeFalse();

    expect(app(SetPropertyFeature::class)($admin, $property, PlatformFeature::NativePos, true))->toBeNull();
    expect(Feature::for($property)->active(NativePos::class))->toBeTrue();

    expect(app(SetPropertyFeature::class)($admin, $property, PlatformFeature::NativePos, false))->toBeNull();
    expect(Feature::for($property)->active(NativePos::class))->toBeFalse();
});

it('refuses to turn Native POS off while a register shift is open', function () {
    $property = Property::factory()->live()->create();
    $admin = User::factory()->admin()->create();
    Feature::for($property)->activate(NativePos::class);

    $this->mock(OpenPosShifts::class, fn ($mock) => $mock->shouldReceive('exist')->andReturn(true));

    expect(app(SetPropertyFeature::class)($admin, $property, PlatformFeature::NativePos, false))->toBe('open_pos_shifts_exist');
    expect(Feature::for($property)->active(NativePos::class))->toBeTrue();
});

it('shows the admin an error toast when the feature cannot be turned off', function () {
    $property = Property::factory()->live()->create();
    $admin = User::factory()->admin()->create();
    Feature::for($property)->activate(NativePos::class);
    $this->mock(OpenPosShifts::class, fn ($mock) => $mock->shouldReceive('exist')->andReturn(true));

    $response = $this->actingAs($admin)->put(route('admin.properties.features.update', [$property, PlatformFeature::NativePos->value]), ['active' => false]);

    expect($response->getSession()->get('inertia.flash_data.toast.type'))->toBe('error');
    expect(Feature::for($property)->active(NativePos::class))->toBeTrue();
});

it('answers 404 on Native POS routes while the feature is off', function () {
    $setup = nativePosSetup();
    Feature::for($setup['property'])->deactivate(NativePos::class);

    $this->actingAs($setup['manager'])->post(route('pos.terminal-mode.enter', $setup['property']->code))->assertNotFound();
    $this->actingAs($setup['manager'])->post(route('settings.pos.devices.store', $setup['property']->code), ['name' => 'Till', 'pos_outlet_id' => $setup['outlet']->id])->assertNotFound();
});

it('lets pos-any pages open with only one of the two POS features on', function () {
    $setup = nativePosSetup();
    $outlets = route('settings.pos-outlets.index', $setup['property']->code);
    $postings = route('finance.pos-postings.index', $setup['property']->code);
    $user = User::factory()->for($setup['property'])->withPermissions(['pos.manage', 'pos.view'])->create();

    $this->actingAs($user)->get($outlets)->assertOk();
    $this->actingAs($user)->get($postings)->assertNotFound();

    Feature::for($setup['property'])->deactivate(NativePos::class);
    $this->actingAs($user)->get($outlets)->assertNotFound();

    Feature::for($setup['property'])->activate(PosIntegration::class);
    $this->actingAs($user)->get($outlets)->assertOk();
    $this->actingAs($user)->get($postings)->assertOk();
});
