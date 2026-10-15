<?php

use App\Enums\DormStrategy;
use App\Enums\MediaDisk;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function validPlatformSettings(array $overrides = []): array
{
    return [
        'require_go_live_approval' => '1',
        'default_dorm_strategy' => DormStrategy::NativeDorm->value,
        'media_disk' => MediaDisk::Local->value,
        ...$overrides,
    ];
}

test('general settings send only the go-live approval setting', function () {
    config(['filesystems.disks.r2.key' => null]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/edit')
            ->where('activeTab', 'general')
            ->where('adminNavigation.3.active', true)
            ->where('settings.requireGoLiveApproval', false)
            ->missing('settings.defaultDormStrategy')
            ->missing('settings.mediaDisk')
            ->missing('mediaDisks')
            ->missing('dormStrategies')
        );
});

dataset('platform settings sections', [
    ['admin.settings.edit', 'admin.settings.update', ['require_go_live_approval' => '1']],
    ['admin.settings.channels', 'admin.settings.channels.update', ['default_dorm_strategy' => 'bed_as_unit']],
    ['admin.settings.storage', 'admin.settings.storage.update', ['media_disk' => 'local']],
]);

test('staff get a 404 on every platform settings section', function (string $getRoute, string $putRoute, array $payload) {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route($getRoute))->assertNotFound();
    $this->put(route($putRoute), $payload)->assertNotFound();
})->with('platform settings sections');

test('guests must sign in for every platform settings section', function (string $getRoute, string $putRoute, array $payload) {
    $this->get(route($getRoute))->assertRedirect(route('login'));
    $this->put(route($putRoute), $payload)->assertRedirect(route('login'));
})->with('platform settings sections');

test('admins without two-factor cannot save any platform settings section', function (string $getRoute, string $putRoute, array $payload) {
    config(['estatell.support_require_two_factor' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route($putRoute), $payload)
        ->assertRedirect();

    expect(PlatformSetting::query()->find(1)->default_dorm_strategy)->toBe(DormStrategy::BedAsUnit);
    expect(Activity::query()->count())->toBe(0);
})->with('platform settings sections');

test('saving general settings changes and logs only go-live approval', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    app(PlatformSettings::class)->current();

    $this->actingAs($admin)
        ->put(route('admin.settings.update'), validPlatformSettings())
        ->assertRedirect(route('admin.settings.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Platform settings saved.']);

    $settings = app(PlatformSettings::class)->current();
    expect($settings->require_go_live_approval)->toBeTrue()
        ->and($settings->default_dorm_strategy)->toBe(DormStrategy::BedAsUnit)
        ->and($settings->media_disk)->toBe(MediaDisk::Local);

    $activity = Activity::query()->sole();
    expect($activity->causer->is($admin))->toBeTrue()
        ->and($activity->attribute_changes->toArray())->toBe([
            'attributes' => ['require_go_live_approval' => true],
            'old' => ['require_go_live_approval' => false],
        ]);
});

test('the automatic radio choice turns go-live approval off', function () {
    PlatformSetting::query()->whereKey(1)->update(['require_go_live_approval' => true]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.update'), ['require_go_live_approval' => '0'])
        ->assertSessionHasNoErrors();

    expect(PlatformSetting::query()->find(1)->require_go_live_approval)->toBeFalse();
});

test('saving without changes writes no log entry', function () {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.update'), validPlatformSettings([
            'require_go_live_approval' => '0',
            'default_dorm_strategy' => DormStrategy::BedAsUnit->value,
        ]))
        ->assertSessionHasNoErrors();

    expect(Activity::query()->count())->toBe(0);
});

test('R2 is rejected until its keys are set', function () {
    config(['filesystems.disks.r2.key' => null]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.storage.update'), ['media_disk' => MediaDisk::R2->value])
        ->assertSessionHasErrors(['media_disk' => 'This storage is not set up yet.']);

    expect(PlatformSetting::query()->find(1)->media_disk)->toBe(MediaDisk::Local);
});

test('R2 can be chosen once its keys are set', function () {
    config([
        'filesystems.disks.r2.key' => 'key',
        'filesystems.disks.r2.secret' => 'secret',
        'filesystems.disks.r2.bucket' => 'bucket',
        'filesystems.disks.r2.endpoint' => 'https://example.r2.cloudflarestorage.com',
    ]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.storage.update'), validPlatformSettings(['media_disk' => MediaDisk::R2->value]))
        ->assertRedirect(route('admin.settings.storage'))
        ->assertSessionHasNoErrors();

    $settings = app(PlatformSettings::class)->current();
    expect($settings->media_disk)->toBe(MediaDisk::R2)
        ->and($settings->require_go_live_approval)->toBeFalse()
        ->and($settings->default_dorm_strategy)->toBe(DormStrategy::BedAsUnit)
        ->and(Activity::query()->sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['media_disk' => 'r2'],
            'old' => ['media_disk' => 'local'],
        ]);
});

test('storage settings send only storage data and disable R2 until configured', function () {
    config(['filesystems.disks.r2.key' => null]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.settings.storage'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/edit')
            ->where('activeTab', 'storage')
            ->where('adminNavigation.3.active', true)
            ->where('settings.mediaDisk', 'local')
            ->where('mediaDisks.0.disabled', false)
            ->where('mediaDisks.1.label', 'Cloudflare R2')
            ->where('mediaDisks.1.disabled', true)
            ->has('mediaDisks.1.description')
            ->missing('settings.requireGoLiveApproval')
            ->missing('settings.defaultDormStrategy')
            ->missing('dormStrategies')
        );
});

test('channel settings send only channel data and disable native dorm pending verification', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.settings.channels'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/edit')
            ->where('activeTab', 'channels')
            ->where('adminNavigation.3.active', true)
            ->where('settings.defaultDormStrategy', 'bed_as_unit')
            ->where('dormStrategies.0.disabled', false)
            ->where('dormStrategies.1.value', 'native_dorm')
            ->where('dormStrategies.1.disabled', true)
            ->has('dormStrategies.1.description')
            ->missing('settings.requireGoLiveApproval')
            ->missing('settings.mediaDisk')
            ->missing('mediaDisks')
        );
});

test('saving channel settings preserves the other sections and returns to the channel tab', function () {
    PlatformSetting::query()->whereKey(1)->update([
        'require_go_live_approval' => true,
        'default_dorm_strategy' => DormStrategy::NativeDorm,
        'media_disk' => MediaDisk::R2,
    ]);
    app(PlatformSettings::class)->current();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.channels.update'), [
            'default_dorm_strategy' => 'bed_as_unit',
            'require_go_live_approval' => false,
            'media_disk' => 'local',
        ])
        ->assertRedirect(route('admin.settings.channels'))
        ->assertSessionHasNoErrors();

    $settings = app(PlatformSettings::class)->current();
    expect($settings->default_dorm_strategy)->toBe(DormStrategy::BedAsUnit)
        ->and($settings->require_go_live_approval)->toBeTrue()
        ->and($settings->media_disk)->toBe(MediaDisk::R2)
        ->and(Activity::query()->sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['default_dorm_strategy' => 'bed_as_unit'],
            'old' => ['default_dorm_strategy' => 'native_dorm'],
        ]);
});

test('native dorm cannot be selected before verification', function () {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.channels.update'), ['default_dorm_strategy' => 'native_dorm'])
        ->assertSessionHasErrors(['default_dorm_strategy' => 'This dorm strategy is not available yet. Native dorm is pending verification.']);

    expect(PlatformSetting::query()->find(1)->default_dorm_strategy)->toBe(DormStrategy::BedAsUnit)
        ->and(Activity::query()->count())->toBe(0);
});

test('saving general settings does not replace an existing native dorm or unconfigured storage choice', function () {
    PlatformSetting::query()->whereKey(1)->update(['default_dorm_strategy' => DormStrategy::NativeDorm, 'media_disk' => MediaDisk::R2]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.update'), ['require_go_live_approval' => '1'])
        ->assertSessionHasNoErrors();

    $settings = PlatformSetting::query()->find(1);
    expect($settings->require_go_live_approval)->toBeTrue()
        ->and($settings->default_dorm_strategy)->toBe(DormStrategy::NativeDorm)
        ->and($settings->media_disk)->toBe(MediaDisk::R2);
});

test('each section requires a valid selection', function (string $routeName, array $payload, string $field) {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route($routeName), $payload)
        ->assertSessionHasErrors($field);

    expect(Activity::query()->count())->toBe(0);
})->with([
    ['admin.settings.update', [], 'require_go_live_approval'],
    ['admin.settings.update', ['require_go_live_approval' => 'invalid'], 'require_go_live_approval'],
    ['admin.settings.channels.update', [], 'default_dorm_strategy'],
    ['admin.settings.channels.update', ['default_dorm_strategy' => 'invalid'], 'default_dorm_strategy'],
    ['admin.settings.storage.update', [], 'media_disk'],
    ['admin.settings.storage.update', ['media_disk' => 'invalid'], 'media_disk'],
]);

test('saving an unchanged section does not log a change', function (string $getRoute, string $putRoute, array $payload) {
    $payload = $putRoute === 'admin.settings.update' ? ['require_go_live_approval' => '0'] : $payload;

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route($putRoute), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route($getRoute));

    expect(Activity::query()->count())->toBe(0);
})->with('platform settings sections');

test('settings are read back from a serializing cache store', function () {
    config(['cache.default' => 'file']);
    Cache::forget(PlatformSettings::CACHE_KEY);

    app(PlatformSettings::class)->current();
    $settings = app(PlatformSettings::class)->current();

    expect($settings)->toBeInstanceOf(PlatformSetting::class)
        ->and($settings->media_disk)->toBe(MediaDisk::Local)
        ->and($settings->exists)->toBeTrue();

    Cache::forget(PlatformSettings::CACHE_KEY);
});
