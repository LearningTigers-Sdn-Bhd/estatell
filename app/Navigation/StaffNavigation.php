<?php

namespace App\Navigation;

use App\Data\Navigation\NavContextData;
use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\StaffNavigationData;
use App\Enums\NavigationContext;
use App\Models\Property;
use App\Models\User;

/**
 * Builds the staff sidebar from one navigation class per context.
 * Empty groups and contexts are dropped, so a context shows only when it has items.
 */
class StaffNavigation
{
    public function __construct(
        private FrontDeskNavigation $frontDesk,
        private PosNavigation $pos,
        private FinanceNavigation $finance,
        private ReportsNavigation $reports,
        private SettingsNavigation $settings,
    ) {}

    public function build(Property $property, User $user, ?string $routeName): StaffNavigationData
    {
        $contexts = [];

        foreach (NavigationContext::cases() as $context) {
            $groups = array_values(array_filter(
                $this->groupsFor($context, $property, $user),
                fn (NavGroupData $group): bool => $group->items !== [],
            ));

            if ($groups === []) {
                continue;
            }

            $contexts[] = new NavContextData(
                key: $context,
                label: $context->label(),
                icon: $context->icon(),
                href: $this->landingHref($groups),
                groups: $groups,
            );
        }

        return new StaffNavigationData(active: NavigationContext::forRoute($routeName), contexts: $contexts);
    }

    /**
     * Prefer the first in-shell item so switching areas lands inside the sidebar; fall back to the first item when
     * every item opens in a dedicated fullscreen tab (such as a cashier with POS register access only).
     *
     * @param  non-empty-list<NavGroupData>  $groups
     */
    private function landingHref(array $groups): string
    {
        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                if (! $item->external) {
                    return $item->href;
                }
            }
        }

        return $groups[0]->items[0]->href;
    }

    /**
     * Each context class leaves out the items the user has no permission for.
     *
     * @return list<NavGroupData>
     */
    private function groupsFor(NavigationContext $context, Property $property, User $user): array
    {
        return match ($context) {
            NavigationContext::FrontDesk => $this->frontDesk->groups($property, $user),
            NavigationContext::Pos => $this->pos->groups($property, $user),
            NavigationContext::Settings => $this->settings->groups($property, $user),
            NavigationContext::Finance => $this->finance->groups($property, $user),
            NavigationContext::Reports => $this->reports->groups($property, $user),
        };
    }
}
