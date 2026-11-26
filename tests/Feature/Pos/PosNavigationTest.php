<?php

use App\Enums\NavigationContext;
use App\Enums\PermissionEnum;
use App\Features\Pos;
use App\Models\Property;
use App\Models\User;
use App\Navigation\FrontDeskNavigation;
use App\Navigation\PosNavigation;
use App\Navigation\SettingsNavigation;
use App\Navigation\StaffNavigation;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;

test('the POS area appears in staff navigation only when the POS feature is enabled', function () {
    $property = Property::factory()->live()->create();
    $user = User::factory()->for($property)->create();
    setPermissionsTeamId($property->id);

    foreach ([PermissionEnum::PosSell, PermissionEnum::PosMenu, PermissionEnum::PosManage] as $permission) {
        Permission::findOrCreate($permission->value, 'web');
        $user->givePermissionTo($permission->value);
    }

    Feature::for($property)->deactivate(Pos::class);
    $off = app(StaffNavigation::class)->build($property, $user, 'dashboard');
    expect(collect($off->contexts)->pluck('key')->all())->not->toContain(NavigationContext::Pos);

    Feature::for($property)->activate(Pos::class);
    $on = app(StaffNavigation::class)->build($property, $user, 'settings.pos.menu.index');
    $posContext = collect($on->contexts)->firstWhere('key', NavigationContext::Pos);

    expect($on->active)->toBe(NavigationContext::Pos)
        ->and($posContext)->not->toBeNull()
        ->and($posContext->href)->toBe(route('settings.pos.menu.index', ['property' => $property->code], absolute: false));
});

test('menu and inventory roles see their POS groups without needing POS manage permission', function () {
    $property = Property::factory()->live()->create();
    Feature::for($property)->activate(Pos::class);

    $chef = User::factory()->for($property)->create();
    setPermissionsTeamId($property->id);
    Permission::findOrCreate(PermissionEnum::PosMenu->value, 'web');
    Permission::findOrCreate(PermissionEnum::PosInventory->value, 'web');
    $chef->givePermissionTo([PermissionEnum::PosMenu->value, PermissionEnum::PosInventory->value]);

    $groups = collect(app(PosNavigation::class)->groups($property, $chef))
        ->filter(fn ($group) => $group->items !== [])
        ->values();

    expect($groups->pluck('label')->all())->toBe(['Menu', 'Service', 'Inventory'])
        ->and(collect($groups->flatMap->items)->pluck('title')->all())->toContain('Menu', 'Floor plan', 'Kitchen stations', 'Inventory & recipes')
        ->and(collect($groups->flatMap->items)->pluck('title')->all())->not->toContain('Outlets', 'API access');
});

test('cashiers with only sell permission land directly on the POS register from the POS area', function () {
    $property = Property::factory()->live()->create();
    Feature::for($property)->activate(Pos::class);

    $cashier = User::factory()->for($property)->create();
    setPermissionsTeamId($property->id);
    Permission::findOrCreate(PermissionEnum::PosSell->value, 'web');
    $cashier->givePermissionTo(PermissionEnum::PosSell->value);

    $nav = app(StaffNavigation::class)->build($property, $cashier, 'dashboard');
    $posContext = collect($nav->contexts)->firstWhere('key', NavigationContext::Pos);

    expect($posContext)->not->toBeNull()
        ->and($posContext->href)->toBe(route('pos.index', ['property' => $property->code], absolute: false));
});

test('front desk and settings navigation no longer duplicate POS links', function () {
    $property = Property::factory()->live()->create();
    Feature::for($property)->activate(Pos::class);

    $owner = User::factory()->for($property)->create();
    setPermissionsTeamId($property->id);

    foreach (PermissionEnum::cases() as $permission) {
        Permission::findOrCreate($permission->value, 'web');
    }
    $owner->givePermissionTo(array_map(fn (PermissionEnum $permission) => $permission->value, PermissionEnum::cases()));

    $frontDeskTitles = collect(app(FrontDeskNavigation::class)->groups($property, $owner))
        ->flatMap->items->pluck('title')->all();
    $settingsTitles = collect(app(SettingsNavigation::class)->groups($property, $owner))
        ->flatMap->items->pluck('title')->all();

    expect($frontDeskTitles)->not->toContain('POS register', 'Kitchen display', 'Meal check-in')
        ->and($settingsTitles)->not->toContain('POS');
});
