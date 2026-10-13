<?php

namespace App\Navigation;

use App\Data\Navigation\NavItemData;

/**
 * Sidebar items for the partner layout.
 */
class PartnerNavigation
{
    /**
     * @return list<NavItemData>
     */
    public function items(): array
    {
        return [
            new NavItemData(title: __('Properties'), href: route('partner.properties.index', absolute: false), icon: 'building'),
        ];
    }
}
