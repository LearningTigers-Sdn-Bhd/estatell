<?php

namespace App\Navigation;

use App\Data\Navigation\NavContextData;
use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Data\Navigation\StaffNavigationData;
use App\Enums\NavigationContext;
use App\Models\PropertyGroup;

/**
 * The sidebar of group mode (`/g/{group}`): one Group context with its pages. Each page shows only the properties
 * where the user's role allows it, so the items need no permission here. See docs/03-staff/05-group-mode.md.
 */
class GroupNavigation
{
    public function build(PropertyGroup $group): StaffNavigationData
    {
        $items = [
            new NavItemData(
                title: __('Dashboard'),
                href: route('group.dashboard', ['propertyGroup' => $group->id], absolute: false),
                icon: 'layout-grid',
                active: request()->routeIs('group.dashboard'),
            ),
            new NavItemData(
                title: __('Reservations'),
                href: route('group.reservations', ['propertyGroup' => $group->id], absolute: false),
                icon: 'calendar-check',
                active: request()->routeIs('group.reservations*'),
            ),
            new NavItemData(
                title: __('Timeline View'),
                href: route('group.timeline', ['propertyGroup' => $group->id], absolute: false),
                icon: 'chart-gantt',
                active: request()->routeIs('group.timeline'),
            ),
            new NavItemData(
                title: __('Forecast'),
                href: route('group.forecast', ['propertyGroup' => $group->id], absolute: false),
                icon: 'trending-up',
                active: request()->routeIs('group.forecast*'),
            ),
            new NavItemData(
                title: __('Reports'),
                href: route('group.reports', ['propertyGroup' => $group->id], absolute: false),
                icon: 'chart-column',
                active: request()->routeIs('group.reports*'),
            ),
            new NavItemData(
                title: __('Property reports'),
                href: route('group.property-reports', ['propertyGroup' => $group->id], absolute: false),
                icon: 'file-text',
                active: request()->routeIs('group.property-reports*'),
            ),
        ];

        return new StaffNavigationData(
            active: NavigationContext::Group,
            contexts: [new NavContextData(
                key: NavigationContext::Group,
                label: $group->name,
                icon: NavigationContext::Group->icon(),
                href: $items[0]->href,
                groups: [new NavGroupData(label: __('All properties'), items: $items)],
            )],
        );
    }
}
