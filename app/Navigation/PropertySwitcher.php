<?php

namespace App\Navigation;

use App\Data\Property\PropertySummaryData;
use App\Http\Middleware\EnsureGroupAccess;
use App\Http\Middleware\EnsurePropertyAccess;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Platform\Impersonation;
use App\Services\Staff\GroupProperties;
use App\Services\Staff\StaffProperties;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The property menu on the navbar for a staff user with more than one property: the groups they may open in group
 * mode and the other properties they may open, and the group of a group-mode page. It reuses what the access
 * middleware already read for the request, so a user with one property costs no query. In a support session (view as
 * staff) it lists only what the support user may view as staff; picking another property goes through the view-as
 * switch, which asks the reason again.
 * See docs/03-staff/04-property-groups.md and 05-group-mode.md.
 */
class PropertySwitcher
{
    public function __construct(
        private StaffProperties $staffProperties,
        private GroupProperties $groupProperties,
        private Impersonation $impersonation,
    ) {}

    /**
     * The other properties the user may open, or in group mode the group's properties they may open.
     *
     * @return list<PropertySummaryData>
     */
    public function properties(Request $request, ?Property $current): array
    {
        $user = $request->user();

        if ($user === null || $user->property_id === null) {
            return [];
        }

        $groupProperties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);

        if ($groupProperties instanceof Collection) {
            return array_values(array_map(
                fn (Property $property): PropertySummaryData => PropertySummaryData::fromModel($property),
                array_filter($groupProperties->all(), fn ($property): bool => $property instanceof Property),
            ));
        }

        if ($current === null) {
            return [];
        }

        if (! $this->impersonation->isActive()) {
            return $this->staffProperties->switcherFor($user, $current, $this->activePropertyIds($request));
        }

        return $this->staffProperties->switcherFor($user, $current, null, fn (Property $property): bool => Gate::forUser($user)->allows('viewAs', $property));
    }

    /**
     * The groups the user may open in group mode (two or more of their properties in one group).
     *
     * @return list<array{id: int, name: string}>
     */
    public function groups(Request $request): array
    {
        $user = $request->user();

        if ($user === null || $user->property_id === null) {
            return [];
        }

        $impersonating = $this->impersonation->isActive();
        $ids = $impersonating ? null : $this->activePropertyIds($request);

        if ($ids !== null && count($ids) < 2) {
            return [];
        }

        return array_values($this->groupProperties->groupsFor($user)
            ->filter(fn (PropertyGroup $group): bool => ! $impersonating || $this->groupProperties->viewableAsStaff($user, $group))
            ->map(fn (PropertyGroup $group): array => ['id' => $group->id, 'name' => $group->name])
            ->all());
    }

    /**
     * The group of a group-mode page (`EnsureGroupAccess` has let the user in), or null.
     */
    public function groupOf(Request $request): ?PropertyGroup
    {
        $group = $request->route('propertyGroup');

        return $group instanceof PropertyGroup && $request->attributes->has(EnsureGroupAccess::PROPERTIES) ? $group : null;
    }

    /**
     * The ids of the properties the user may open, as `EnsurePropertyAccess` read them, or null on a page it did not
     * run on.
     *
     * @return list<int>|null
     */
    private function activePropertyIds(Request $request): ?array
    {
        $ids = $request->attributes->get(EnsurePropertyAccess::ACTIVE_PROPERTY_IDS);

        return is_array($ids) ? array_values(array_filter($ids, is_int(...))) : null;
    }
}
