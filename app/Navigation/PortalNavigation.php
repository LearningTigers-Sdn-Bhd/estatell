<?php

namespace App\Navigation;

use App\Data\Navigation\NavItemData;
use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Sidebar items for the company portal layout: the property list, and inside a property its dashboard, Book, Bookings, Statements and Company.
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

        if ($request->attributes->has('portalLink') && $property instanceof Property) {
            $code = ['property' => $property->code];

            $items[] = new NavItemData(title: __('Dashboard'), href: route('portal.show', $code, absolute: false), icon: 'layout-grid', active: $request->routeIs('portal.show'));
            $items[] = new NavItemData(title: __('Book'), href: route('portal.book', $code, absolute: false), icon: 'calendar-check', active: $request->routeIs('portal.book', 'portal.book.*'));
            $items[] = new NavItemData(title: __('Bookings'), href: route('portal.bookings.index', $code, absolute: false), icon: 'calendar-range', active: $request->routeIs('portal.bookings.*'));
            $items[] = new NavItemData(title: __('Statements'), href: route('portal.statements', $code, absolute: false), icon: 'receipt', active: $request->routeIs('portal.statements', 'portal.statements.*'));
            $items[] = new NavItemData(title: __('Company'), href: route('portal.company', $code, absolute: false), icon: 'id-card', active: $request->routeIs('portal.company', 'portal.company.*'));
        }

        return $items;
    }
}
