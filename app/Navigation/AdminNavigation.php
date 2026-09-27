<?php

namespace App\Navigation;

use App\Data\Navigation\NavItemData;

/**
 * Sidebar items for the platform admin layout.
 */
class AdminNavigation
{
    /**
     * @return list<NavItemData>
     */
    public function items(): array
    {
        return [
            new NavItemData(title: __('Dashboard'), href: route('admin.dashboard', absolute: false), icon: 'layout-grid'),
            new NavItemData(title: __('Properties'), href: route('admin.properties.index', absolute: false), icon: 'building'),
            new NavItemData(title: __('Settings'), href: route('admin.settings.edit', absolute: false), icon: 'settings'),
            new NavItemData(title: __('Horizon'), href: route('horizon.index', absolute: false), icon: 'activity', external: true),
        ];
    }
}
