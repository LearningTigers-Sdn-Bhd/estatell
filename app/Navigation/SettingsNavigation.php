<?php

namespace App\Navigation;

use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Models\Property;

/**
 * Sidebar groups for the Settings context. See docs/design-system/09-app-shell.md.
 */
class SettingsNavigation
{
    /**
     * @return list<NavGroupData>
     */
    public function groups(Property $property): array
    {
        return [
            new NavGroupData(label: __('Property'), items: [
                new NavItemData(
                    title: __('Details'),
                    href: route('settings.edit', ['property' => $property->code], absolute: false),
                    icon: 'building',
                    active: request()->routeIs('settings.edit', 'settings.operations', 'settings.registration', 'settings.support'),
                ),
            ]),
        ];
    }
}
