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
            new NavGroupData(label: __('Team'), items: array_values(array_filter([
                $user->can(PermissionEnum::StaffView->value) ? new NavItemData(
                    title: __('Staff'),
                    href: route('settings.staff.index', ['property' => $property->code], absolute: false),
                    icon: 'users',
                    active: request()->routeIs('settings.staff.*'),
                ) : null,
            ]))),
        ];
    }
}
