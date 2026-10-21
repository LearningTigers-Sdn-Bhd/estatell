<?php

namespace App\Navigation;

use App\Data\Navigation\NavItemData;
use App\Enums\PropertyStatus;
use App\Models\Property;

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
            new NavItemData(title: __('Properties'), href: route('admin.properties.index', absolute: false), icon: 'building', badge: $this->waitingForApproval()),
            new NavItemData(title: __('Partners'), href: route('admin.partners.index', absolute: false), icon: 'handshake'),
            new NavItemData(title: __('Settings'), href: route('admin.settings.edit', absolute: false), icon: 'settings', active: request()->routeIs('admin.settings.*')),
            new NavItemData(title: __('Channels'), href: route('admin.channels.index', absolute: false), icon: 'waypoints', active: request()->routeIs('admin.channels.*')),
            new NavItemData(title: __('Mail log'), href: route('admin.mail-events.index', absolute: false), icon: 'mail', active: request()->routeIs('admin.mail-events.*')),
            new NavItemData(title: __('Horizon'), href: route('horizon.index', absolute: false), icon: 'activity', external: true),
        ];
    }

    /**
     * How many properties wait for a go-live decision, or null when none do.
     */
    private function waitingForApproval(): ?int
    {
        return Property::query()->where('status', PropertyStatus::PendingApproval)->count() ?: null;
    }
}
