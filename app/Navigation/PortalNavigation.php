<?php

namespace App\Navigation;

use App\Data\Navigation\NavItemData;
use Illuminate\Http\Request;

/**
 * Sidebar items for the company portal layout: the property list, and inside a property its dashboard.
 */
class PortalNavigation
{
    /**
     * @return list<NavItemData>
     */
    public function items(Request $request): array
    {
        $items = [
            new NavItemData(title: __('Properties'), href: route('portal.index', absolute: false), icon: 'building', active: $request->routeIs('portal.index')),
        ];

        $property = $request->route('property');

        if ($request->attributes->has('portalLink') && is_object($property)) {
            $items[] = new NavItemData(title: __('Dashboard'), href: route('portal.show', ['property' => $property->code], absolute: false), icon: 'layout-grid', active: $request->routeIs('portal.show'));
        }

        return $items;
    }
}
