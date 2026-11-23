<?php

namespace App\Services\Staff;

use App\Data\Property\PropertySummaryData;
use App\Data\Staff\GroupColleagueData;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\PropertyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The properties a staff user may open, through their memberships: whether they may open one, where sign-in lands,
 * the property switcher, and who from the rest of the group can be given access to a property. Reads only.
 * See docs/03-staff/04-property-groups.md.
 */
class StaffProperties
{
    /**
     * The ids of the properties the staff user may open now, in one query. Empty for a deactivated account.
     *
     * @return list<int>
     */
    public function activePropertyIds(User $user): array
    {
        if ($user->deactivated_at !== null || $user->property_id === null) {
            return [];
        }

        return array_values(array_map(intval(...), PropertyMembership::query()
            ->where('user_id', $user->id)
            ->whereNull('deactivated_at')
            ->pluck('property_id')
            ->all()));
    }

    /**
     * Where a staff user lands with no property in the URL: the property opened last, else the home property, else the
     * first other property by name, as long as the membership there is active. Null when none is.
     */
    public function defaultFor(User $user): ?Property
    {
        if ($user->property_id === null) {
            return null;
        }

        return Property::query()
            ->whereHas('memberships', fn (Builder $memberships) => $memberships->where('user_id', $user->id)->whereNull('deactivated_at'))
            ->orderByRaw('id = ? DESC', [$user->last_property_id ?? 0])
            ->orderByRaw('id = ? DESC', [$user->property_id])
            ->orderBy('name')
            ->first();
    }

    /**
     * The other properties the staff user may open, by name, for the switcher. Suspended properties are left out.
     * `$activePropertyIds` (from `activePropertyIds()`, which `EnsurePropertyAccess` already read for the request)
     * saves the query for a user with one property, the usual case.
     *
     * @param  list<int>|null  $activePropertyIds
     * @param  (\Closure(Property): bool)|null  $visible  Keeps only the properties it accepts (a support session's view-as check).
     * @return list<PropertySummaryData>
     */
    public function switcherFor(User $user, Property $current, ?array $activePropertyIds = null, ?\Closure $visible = null): array
    {
        $ids = array_values(array_diff($activePropertyIds ?? $this->activePropertyIds($user), [$current->id]));

        if ($ids === []) {
            return [];
        }

        return array_values(Property::query()
            ->whereKey($ids)
            ->where('status', '<>', PropertyStatus::Suspended->value)
            ->with('media')
            ->orderBy('name')
            ->get()
            ->filter(fn (Property $property): bool => $visible === null || $visible($property))
            ->map(fn (Property $property): PropertySummaryData => PropertySummaryData::fromModel($property))
            ->all());
    }

    /**
     * The staff of the group's other properties who can be given access here: active at their home property, that
     * home property in the same group, and no membership here yet (a deactivated one is reactivated instead). Empty
     * for a property with no group.
     *
     * @return list<GroupColleagueData>
     */
    public function colleaguesFor(Property $property): array
    {
        if ($property->property_group_id === null) {
            return [];
        }

        return array_values(User::query()
            ->whereHas('property', fn (Builder $home) => $home->where('property_group_id', $property->property_group_id)->whereKeyNot($property->id))
            ->whereHas('memberships', fn (Builder $memberships) => $memberships->whereColumn('property_memberships.property_id', 'users.property_id')->whereNull('deactivated_at'))
            ->whereDoesntHave('memberships', fn (Builder $memberships) => $memberships->where('property_id', $property->id))
            ->with('property')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): GroupColleagueData => GroupColleagueData::fromUser($user))
            ->all());
    }
}
