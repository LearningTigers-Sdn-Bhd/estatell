<?php

namespace App\Navigation;

use App\Data\Navigation\NavContextData;
use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Data\Navigation\StaffNavigationData;
use App\Enums\NavigationContext;
use App\Enums\ReportCategory;
use App\Enums\ReportKey;
use App\Http\Requests\Group\GroupPropertyReportRequest;
use App\Models\PropertyGroup;

/**
 * The sidebar of group mode (`/g/{group}`): one Group context with an Overview section (dashboard, reservations,
 * timeline) and a Reports section (forecast, performance, and the property reports by category). Each page shows only the properties
 * where the user's role allows it, so the items need no permission here. See docs/03-staff/05-group-mode.md.
 */
class GroupNavigation
{
    /** The sidebar icon of each report category. */
    private const array CATEGORY_ICONS = [
        'front-desk' => 'concierge-bell',
        'revenue' => 'chart-pie',
        'finance' => 'wallet',
        'kitchen' => 'utensils',
        'audit' => 'moon',
    ];

    public function build(PropertyGroup $group): StaffNavigationData
    {
        $key = ['propertyGroup' => $group->id];
        $overview = [
            new NavItemData(
                title: __('Dashboard'),
                href: route('group.dashboard', $key, absolute: false),
                icon: 'layout-grid',
                active: request()->routeIs('group.dashboard'),
            ),
            new NavItemData(
                title: __('Reservations'),
                href: route('group.reservations', $key, absolute: false),
                icon: 'calendar-check',
                active: request()->routeIs('group.reservations*'),
            ),
            new NavItemData(
                title: __('Timeline View'),
                href: route('group.timeline', $key, absolute: false),
                icon: 'chart-gantt',
                active: request()->routeIs('group.timeline'),
            ),
        ];
        $openCategory = request()->routeIs('group.property-reports*')
            ? (ReportKey::tryFrom((string) request()->query('report'))?->category() ?? ReportCategory::tryFrom((string) request()->query('category')) ?? ReportCategory::FrontDesk)
            : null;
        $reports = [
            new NavItemData(
                title: __('Forecast'),
                href: route('group.forecast', $key, absolute: false),
                icon: 'trending-up',
                active: request()->routeIs('group.forecast*'),
            ),
            new NavItemData(
                title: __('Performance'),
                href: route('group.reports', $key, absolute: false),
                icon: 'chart-column',
                active: request()->routeIs('group.reports*'),
            ),
            ...array_map(fn (ReportCategory $category): NavItemData => new NavItemData(
                title: $category->label(),
                href: route('group.property-reports', [...$key, 'category' => $category->value], absolute: false),
                icon: self::CATEGORY_ICONS[$category->value],
                active: $openCategory === $category,
            ), GroupPropertyReportRequest::categories()),
        ];

        return new StaffNavigationData(
            active: NavigationContext::Group,
            contexts: [new NavContextData(
                key: NavigationContext::Group,
                label: $group->name,
                icon: NavigationContext::Group->icon(),
                href: $overview[0]->href,
                groups: [
                    new NavGroupData(label: __('Overview'), items: $overview),
                    new NavGroupData(label: __('Reports'), items: $reports),
                ],
            )],
        );
    }
}
