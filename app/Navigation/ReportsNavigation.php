<?php

namespace App\Navigation;

use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Enums\PermissionEnum;
use App\Enums\ReportCategory;
use App\Enums\ReportKey;
use App\Models\Property;
use App\Models\User;

/**
 * Sidebar groups for the Reports context: registered reports and the read-only activity log.
 * Without `reports.view` there are no items, so the context is dropped.
 */
class ReportsNavigation
{
    /**
     * @return list<NavGroupData>
     */
    public function groups(Property $property, User $user): array
    {
        if (! $user->can(PermissionEnum::ReportsView->value)) {
            return [];
        }

        $groups = array_map(
            fn (ReportCategory $category): NavGroupData => new NavGroupData(
                label: $category->label(),
                items: [...array_values(array_map(
                    fn (ReportKey $key): NavItemData => new NavItemData(
                        title: $key->label(),
                        href: route('reports.'.$key->value, ['property' => $property->code], absolute: false),
                        icon: $key->icon(),
                        active: request()->routeIs('reports.'.$key->value),
                    ),
                    array_filter(ReportKey::cases(), fn (ReportKey $key): bool => $key->listedInReportsMenu() && $key->isAvailable($property) && $key->category() === $category),
                )), ...($category === ReportCategory::Audit ? [new NavItemData(
                    title: __('Activity log'),
                    href: route('reports.activity-log', ['property' => $property->code], absolute: false),
                    icon: 'activity',
                    active: request()->routeIs('reports.activity-log'),
                )] : [])],
            ),
            ReportCategory::cases(),
        );

        return array_values(array_filter($groups, fn (NavGroupData $group): bool => $group->items !== []));
    }
}
