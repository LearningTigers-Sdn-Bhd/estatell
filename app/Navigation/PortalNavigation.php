<?php

namespace App\Navigation;

use App\Data\Navigation\NavItemData;
use App\Data\Portal\PortalSwitcherData;
use App\Enums\CompanyUserStatus;
use App\Enums\PropertyStatus;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Sidebar items for the company portal layout. Outside a property: Dashboard and Properties. Inside `/portal/{code}`:
 * Dashboard, Bookings, Statements and Company details, plus the list for the property switcher.
 */
class PortalNavigation
{
    /**
     * @return list<NavItemData>
     */
    public function items(Request $request): array
    {
        $property = $request->route('property');

        if ($request->attributes->has('portalLink') && $property instanceof Property) {
            $code = ['property' => $property->code];

            return [
                new NavItemData(title: __('Dashboard'), href: route('portal.show', $code, absolute: false), icon: 'layout-grid', active: $request->routeIs('portal.show')),
                new NavItemData(title: __('Bookings'), href: route('portal.bookings.index', $code, absolute: false), icon: 'calendar-range', active: $request->routeIs('portal.bookings.*')),
                new NavItemData(title: __('Statements'), href: route('portal.statements', $code, absolute: false), icon: 'receipt', active: $request->routeIs('portal.statements', 'portal.statements.*')),
                new NavItemData(title: __('Company details'), href: route('portal.company', $code, absolute: false), icon: 'id-card', active: $request->routeIs('portal.company', 'portal.company.*')),
            ];
        }

        return [
            new NavItemData(title: __('Dashboard'), href: route('portal.index', absolute: false), icon: 'layout-grid', active: $request->routeIs('portal.index')),
            new NavItemData(title: __('Properties'), href: route('portal.properties', absolute: false), icon: 'building', active: $request->routeIs('portal.properties')),
        ];
    }

    /**
     * The user's other Active links at Live properties, for the property switcher inside a property. Null outside one.
     *
     * @return list<PortalSwitcherData>|null
     */
    public function switcher(Request $request, User $user): ?array
    {
        $link = $request->attributes->get('portalLink');

        if (! $link instanceof CompanyUser) {
            return null;
        }

        return CompanyUser::query()
            ->where('user_id', $user->id)
            ->where('status', CompanyUserStatus::Active->value)
            ->where('property_id', '<>', $link->property_id)
            ->whereHas('property', fn ($query) => $query->where('status', PropertyStatus::Live->value))
            ->with('company', 'property')
            ->get()
            ->sortBy(fn (CompanyUser $other): string => mb_strtolower($other->property->name))
            ->map(fn (CompanyUser $other): PortalSwitcherData => PortalSwitcherData::fromLink($other))
            ->values()
            ->all();
    }
}
