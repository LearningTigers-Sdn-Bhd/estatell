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
        'require_go_live_approval' => 'on',
        'default_dorm_strategy' => DormStrategy::NativeDorm->value,
        'media_disk' => MediaDisk::Local->value,
        ...$overrides,
    ];
}

test('admins see the platform settings with R2 disabled until it is set up', function () {
    config(['filesystems.disks.r2.key' => null]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/edit')
            ->where('settings.requireGoLiveApproval', false)
            ->where('settings.defaultDormStrategy', DormStrategy::BedAsUnit->value)
            ->where('settings.mediaDisk', MediaDisk::Local->value)
            ->where('mediaDisks.1', ['value' => 'r2', 'label' => 'Cloudflare R2', 'disabled' => true])
        );
});

test('staff get a 404 on platform settings', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('admin.settings.edit'))->assertNotFound();
    $this->actingAs($staff)->put(route('admin.settings.update'), validPlatformSettings())->assertNotFound();
});

test('admins without two-factor cannot save platform settings', function () {
    config(['estatell.admin_require_two_factor' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.settings.update'), validPlatformSettings())
        ->assertRedirect();

    expect(PlatformSetting::query()->find(1)->default_dorm_strategy)->toBe(DormStrategy::BedAsUnit);
});

test('admins save platform settings and the change is logged', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    app(PlatformSettings::class)->current();

    $this->actingAs($admin)
        ->put(route('admin.settings.update'), validPlatformSettings())
        ->assertRedirect(route('admin.settings.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Platform settings saved.']);

    $settings = app(PlatformSettings::class)->current();
    expect($settings->require_go_live_approval)->toBeTrue()
        ->and($settings->default_dorm_strategy)->toBe(DormStrategy::NativeDorm);

    $activity = Activity::query()->sole();
    expect($activity->causer->is($admin))->toBeTrue()
        ->and($activity->attribute_changes->toArray())->toBe([
            'attributes' => ['require_go_live_approval' => true, 'default_dorm_strategy' => 'native_dorm'],
            'old' => ['require_go_live_approval' => false, 'default_dorm_strategy' => 'bed_as_unit'],
        ]);
});

test('an unchecked checkbox turns go-live approval off', function () {
    PlatformSetting::query()->whereKey(1)->update(['require_go_live_approval' => true]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.update'), validPlatformSettings(['require_go_live_approval' => null]))
        ->assertSessionHasNoErrors();

    expect(PlatformSetting::query()->find(1)->require_go_live_approval)->toBeFalse();
});

test('saving without changes writes no log entry', function () {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.update'), validPlatformSettings([
            'require_go_live_approval' => null,
            'default_dorm_strategy' => DormStrategy::BedAsUnit->value,
        ]))
        ->assertSessionHasNoErrors();

    expect(Activity::query()->count())->toBe(0);
});

test('R2 is rejected until its keys are set', function () {
    config(['filesystems.disks.r2.key' => null]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.settings.update'), validPlatformSettings(['media_disk' => MediaDisk::R2->value]))
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
        ->put(route('admin.settings.update'), validPlatformSettings(['media_disk' => MediaDisk::R2->value]))
        ->assertSessionHasNoErrors();

    expect(app(PlatformSettings::class)->current()->media_disk)->toBe(MediaDisk::R2);
});

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
