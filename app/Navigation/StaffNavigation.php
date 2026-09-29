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
                shortcut: $context->shortcut(),
                href: $groups[0]->items[0]->href,
                groups: $groups,
            );
        }

        return new StaffNavigationData(active: NavigationContext::forRoute($routeName), contexts: $contexts);
    }

    /**
     * Finance and Reports get their navigation classes with their modules.
     * Each context class leaves out the items the user has no permission for.
     *
     * @return list<NavGroupData>
     */
    private function groupsFor(NavigationContext $context, Property $property, User $user): array
    {
        return match ($context) {
            NavigationContext::FrontDesk => $this->frontDesk->groups($property, $user),
            NavigationContext::Settings => $this->settings->groups($property, $user),
            NavigationContext::Finance, NavigationContext::Reports => [],
        };
    }
}
