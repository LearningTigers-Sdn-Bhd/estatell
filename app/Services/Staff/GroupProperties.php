<?php

namespace App\Services\Staff;

use App\Enums\PermissionEnum;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The properties of a group a staff user may see together in group mode (`/g/{group}`): Live properties of the group
 * where their membership is active. Group mode opens only for a user with two or more of them. What they see of each
 * property follows their role there (`permissionsFor()`). Reads only. See docs/03-staff/05-group-mode.md.
 */
class GroupProperties
{
    /**
     * The groups where the user may open two or more Live properties, by name, for the property menu.
     *
     * @return Collection<int, PropertyGroup>
     */
    public function groupsFor(User $user): Collection
    {
        if ($user->property_id === null || $user->deactivated_at !== null) {
            return new Collection;
        }

        return PropertyGroup::query()
            ->whereIn('id', $this->openable($user)->select('property_group_id')->groupBy('property_group_id')->havingRaw('COUNT(*) >= 2'))
            ->orderBy('name')
            ->get();
    }

    /**
     * The Live properties of the group the user may open, by name.
     *
     * @return Collection<int, Property>
     */
    public function propertiesFor(User $user, PropertyGroup $group): Collection
    {
        if ($user->property_id === null || $user->deactivated_at !== null) {
            return new Collection;
        }

        return $this->openable($user)->where('property_group_id', $group->id)->with(['settings', 'media'])->orderBy('name')->get();
    }

    /**
     * The permissions the user holds at each property given, through their role there, in one query. Only the
     * permissions asked for are returned.
     *
     * @param  Collection<int, Property>  $properties
     * @param  list<PermissionEnum>  $permissions
     * @return array<int, list<string>> Permission names by property id; a property where the user holds none is missing.
     */
    public function permissionsFor(User $user, Collection $properties, array $permissions): array
    {
        if ($properties->isEmpty()) {
            return [];
        }

        $rows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->whereColumn('model_has_roles.property_id', 'roles.property_id')
            ->whereIn('roles.property_id', $properties->modelKeys())
            ->whereIn('permissions.name', array_map(fn (PermissionEnum $permission): string => $permission->value, $permissions))
            ->distinct()
            ->get(['roles.property_id', 'permissions.name']);

        $byProperty = [];

        foreach ($rows as $row) {
            $byProperty[(int) $row->property_id][] = (string) $row->name;
        }

        return $byProperty;
    }

    /**
     * @return Builder<Property>
     */
    private function openable(User $user): Builder
    {
        return Property::query()
            ->whereNotNull('property_group_id')
            ->where('status', PropertyStatus::Live->value)
            ->whereHas('memberships', fn (Builder $memberships) => $memberships->where('user_id', $user->id)->whereNull('deactivated_at'));
    }
}
