<?php

namespace App\Navigation;

use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;

/**
 * Sidebar groups for the Front desk context. Items the user has no permission for are left out.
 * See docs/design-system/09-app-shell.md.
 */
class FrontDeskNavigation
{
    /**
     * @return list<NavGroupData>
     */
    public function groups(Property $property, User $user): array
    {
        return [
            new NavGroupData(label: __('Overview'), items: [
                new NavItemData(
                    title: __('Dashboard'),
                    href: route('dashboard', ['property' => $property->code], absolute: false),
                    icon: 'layout-grid',
                    active: request()->routeIs('dashboard'),
                ),
            ]),
            new NavGroupData(label: __('Rooms'), items: array_values(array_filter([
                $user->can(PermissionEnum::RoomsBlock->value) ? new NavItemData(
                    title: __('Room blocks'),
                    href: route('front-desk.room-blocks.index', ['property' => $property->code], absolute: false),
                    icon: 'ban',
                    active: request()->routeIs('front-desk.room-blocks.*'),
                ) : null,
            ]))),
        ];
    }
}
