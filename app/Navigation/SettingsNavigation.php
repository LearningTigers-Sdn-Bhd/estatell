<?php

namespace App\Navigation;

use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;

/**
 * Sidebar groups for the Settings context. Items the user has no permission for are left out.
 * See docs/design-system/09-app-shell.md.
 */
class SettingsNavigation
{
    /**
     * @return list<NavGroupData>
     */
    public function groups(Property $property, User $user): array
    {
        return [
            new NavGroupData(label: __('Property'), items: array_values(array_filter([
                $user->can(PermissionEnum::SettingsManage->value) ? new NavItemData(
                    title: __('Details'),
                    href: route('settings.edit', ['property' => $property->code], absolute: false),
                    icon: 'building',
                    active: request()->routeIs('settings.edit', 'settings.operations', 'settings.registration', 'settings.support'),
                ) : null,
            ]))),
            new NavGroupData(label: __('Rooms'), items: array_values(array_filter([
                $user->can(PermissionEnum::RoomsView->value) ? new NavItemData(
                    title: __('Room types'),
                    href: route('settings.room-types.index', ['property' => $property->code], absolute: false),
                    icon: 'bed',
                    active: request()->routeIs('settings.room-types.*'),
                ) : null,
                $user->can(PermissionEnum::RoomsView->value) ? new NavItemData(
                    title: __('Rooms'),
                    href: route('settings.rooms.index', ['property' => $property->code], absolute: false),
                    icon: 'door-open',
                    active: request()->routeIs('settings.rooms.*', 'settings.room-groups.*'),
                ) : null,
            ]))),
            new NavGroupData(label: __('Rates'), items: array_values(array_filter([
                $user->can(PermissionEnum::SettingsManage->value) ? new NavItemData(
                    title: __('Meal periods'),
                    href: route('settings.meal-periods.index', ['property' => $property->code], absolute: false),
                    icon: 'utensils',
                    active: request()->routeIs('settings.meal-periods.*'),
                ) : null,
            ]))),
            new NavGroupData(label: __('Billing setup'), items: array_values(array_filter([
                $user->can(PermissionEnum::SettingsManage->value) ? new NavItemData(
                    title: __('Taxes'),
                    href: route('settings.taxes.index', ['property' => $property->code], absolute: false),
                    icon: 'landmark',
                    active: request()->routeIs('settings.taxes.*'),
                ) : null,
                $user->can(PermissionEnum::SettingsManage->value) ? new NavItemData(
                    title: __('Charge items'),
                    href: route('settings.charge-items.index', ['property' => $property->code], absolute: false),
                    icon: 'wallet',
                    active: request()->routeIs('settings.charge-items.*'),
                ) : null,
                $user->can(PermissionEnum::SettingsManage->value) ? new NavItemData(
                    title: __('Payment methods'),
                    href: route('settings.payment-methods.index', ['property' => $property->code], absolute: false),
                    icon: 'credit-card',
                    active: request()->routeIs('settings.payment-methods.*'),
                ) : null,
                $user->can(PermissionEnum::SettingsManage->value) ? new NavItemData(
                    title: __('Room revenue'),
                    href: route('settings.room-revenue.index', ['property' => $property->code], absolute: false),
                    icon: 'bed',
                    active: request()->routeIs('settings.room-revenue.*'),
                ) : null,
                $user->can(PermissionEnum::SettingsManage->value) ? new NavItemData(
                    title: __('Discounts'),
                    href: route('settings.discounts.index', ['property' => $property->code], absolute: false),
                    icon: 'percent',
                    active: request()->routeIs('settings.discounts.*'),
                ) : null,
            ]))),
            new NavGroupData(label: __('Team'), items: array_values(array_filter([
                $user->can(PermissionEnum::StaffView->value) ? new NavItemData(
                    title: __('Staff'),
                    href: route('settings.staff.index', ['property' => $property->code], absolute: false),
                    icon: 'users',
                    active: request()->routeIs('settings.staff.*'),
                ) : null,
                $user->can(PermissionEnum::RolesManage->value) ? new NavItemData(
                    title: __('Roles'),
                    href: route('settings.roles.index', ['property' => $property->code], absolute: false),
                    icon: 'shield',
                    active: request()->routeIs('settings.roles.*'),
                ) : null,
            ]))),
            new NavGroupData(label: __('System'), items: array_values(array_filter([
                $user->can(PermissionEnum::NotificationManage->value) ? new NavItemData(
                    title: __('Notifications'),
                    href: route('settings.notifications.index', ['property' => $property->code], absolute: false),
                    icon: 'bell',
                    active: request()->routeIs('settings.notifications.*'),
                ) : null,
            ]))),
        ];
    }
}
