<?php

namespace App\Navigation;

use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Enums\PermissionEnum;
use App\Features\Pos;
use App\Models\Property;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * Sidebar groups for the POS context. Empty while the property's POS feature is off, so `StaffNavigation` drops the
 * area automatically. Items the user has no permission for are left out. See docs/design-system/09-app-shell.md.
 */
class PosNavigation
{
    /**
     * @return list<NavGroupData>
     */
    public function groups(Property $property, User $user): array
    {
        if (! Feature::for($property)->active(Pos::class)) {
            return [];
        }

        return [
            new NavGroupData(label: __('Live'), items: array_values(array_filter([
                $user->can(PermissionEnum::PosSell->value) ? new NavItemData(
                    title: __('POS register'),
                    href: route('pos.index', ['property' => $property->code], absolute: false),
                    icon: 'receipt',
                    external: true,
                    active: request()->routeIs('pos.*') && ! request()->routeIs('pos.kds.*', 'pos.ods.*'),
                ) : null,
                $user->can(PermissionEnum::PosKds->value) ? new NavItemData(
                    title: __('Kitchen display'),
                    href: route('pos.kds.index', ['property' => $property->code], absolute: false),
                    icon: 'chef-hat',
                    external: true,
                    active: request()->routeIs('pos.kds.*', 'pos.ods.*'),
                ) : null,
            ]))),
            new NavGroupData(label: __('Menu'), items: array_values(array_filter([
                $user->can(PermissionEnum::PosMenu->value) ? new NavItemData(
                    title: __('Menu'),
                    href: route('settings.pos.menu.index', ['property' => $property->code], absolute: false),
                    icon: 'book-open',
                    active: request()->routeIs('settings.pos.menu.*'),
                ) : null,
                $user->can(PermissionEnum::PosMenu->value) ? new NavItemData(
                    title: __('Modifiers'),
                    href: route('settings.pos.modifiers.index', ['property' => $property->code], absolute: false),
                    icon: 'layers',
                    active: request()->routeIs('settings.pos.modifiers.*'),
                ) : null,
                $user->can(PermissionEnum::PosMenu->value) ? new NavItemData(
                    title: __('Schedules & combos'),
                    href: route('settings.pos.schedules.index', ['property' => $property->code], absolute: false),
                    icon: 'calendar-clock',
                    active: request()->routeIs('settings.pos.schedules.*'),
                ) : null,
                $user->can(PermissionEnum::PosMenu->value) ? new NavItemData(
                    title: __('Dining options'),
                    href: route('settings.pos.dining-options.index', ['property' => $property->code], absolute: false),
                    icon: 'utensils-crossed',
                    active: request()->routeIs('settings.pos.dining-options.*'),
                ) : null,
            ]))),
            new NavGroupData(label: __('Service'), items: array_values(array_filter([
                $user->can(PermissionEnum::MealsRedeem->value) ? new NavItemData(
                    title: __('Meal check-in'),
                    href: route('restaurant.meal-check-in.index', ['property' => $property->code], absolute: false),
                    icon: 'utensils',
                    active: request()->routeIs('restaurant.meal-check-in.*'),
                ) : null,
                $user->can(PermissionEnum::PosMenu->value) ? new NavItemData(
                    title: __('Floor plan'),
                    href: route('settings.pos.floors.index', ['property' => $property->code], absolute: false),
                    icon: 'layout-grid',
                    active: request()->routeIs('settings.pos.floors.*'),
                ) : null,
                $user->can(PermissionEnum::PosMenu->value) ? new NavItemData(
                    title: __('Kitchen stations'),
                    href: route('settings.pos.kitchen.index', ['property' => $property->code], absolute: false),
                    icon: 'chef-hat',
                    active: request()->routeIs('settings.pos.kitchen.*'),
                ) : null,
            ]))),
            new NavGroupData(label: __('Inventory'), items: $user->can(PermissionEnum::PosInventory->value) ? [
                new NavItemData(
                    title: __('Inventory & recipes'),
                    href: route('settings.pos.inventory.index', ['property' => $property->code], absolute: false),
                    icon: 'boxes',
                    active: request()->routeIs('settings.pos.inventory.*'),
                ),
            ] : []),
            new NavGroupData(label: __('Setup'), items: array_values(array_filter([
                $user->can(PermissionEnum::PosManage->value) ? new NavItemData(
                    title: __('Outlets'),
                    href: route('settings.pos-outlets.index', ['property' => $property->code], absolute: false),
                    icon: 'store',
                    active: request()->routeIs('settings.pos-outlets.*'),
                ) : null,
                $user->can(PermissionEnum::PosManage->value) ? new NavItemData(
                    title: __('Registers & accounts'),
                    href: route('settings.pos.outlets.index', ['property' => $property->code], absolute: false),
                    icon: 'monitor',
                    active: request()->routeIs('settings.pos.outlets.*'),
                ) : null,
                $user->can(PermissionEnum::PosManage->value) ? new NavItemData(
                    title: __('Staff & terminals'),
                    href: route('settings.pos.staff-pins.index', ['property' => $property->code], absolute: false),
                    icon: 'key-round',
                    active: request()->routeIs('settings.pos.staff-pins.*'),
                ) : null,
                $user->can(PermissionEnum::PosManage->value) ? new NavItemData(
                    title: __('API access'),
                    href: route('settings.pos-api-clients.index', ['property' => $property->code], absolute: false),
                    icon: 'webhook',
                    active: request()->routeIs('settings.pos-api-clients.*'),
                ) : null,
            ]))),
        ];
    }
}
