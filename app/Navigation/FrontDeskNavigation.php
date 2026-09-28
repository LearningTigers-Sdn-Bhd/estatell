<?php

namespace App\Navigation;

use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Models\Property;

/**
 * Sidebar groups for the Front desk context. See docs/design-system/09-app-shell.md.
 */
class FrontDeskNavigation
{
    /**
     * @return list<NavGroupData>
     */
    public function groups(Property $property): array
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
        ];
    }
}
