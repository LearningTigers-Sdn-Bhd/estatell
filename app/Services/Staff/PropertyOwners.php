<?php

namespace App\Services\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds each property's owner: the first user with the Owner role there whose membership is active, or the first
 * deactivated one when none is active. Works without a permission team, so admin pages can use it across properties.
 */
class PropertyOwners
{
    /**
     * Add `owner_name` and `owner_email` columns to a properties query, one subquery each.
     *
     * @param  Builder<Property>  $query
     */
    public function addOwnerColumns(Builder $query): void
    {
        $query->addSelect([
            'owner_name' => $this->ownersQuery()->whereColumn('roles.property_id', 'properties.id')->select('users.name')->limit(1),
            'owner_email' => $this->ownersQuery()->whereColumn('roles.property_id', 'properties.id')->select('users.email')->limit(1),
        ]);
    }

    public function firstFor(Property $property): ?User
    {
        return $this->ownersQuery()->where('roles.property_id', $property->id)->select('users.*')->first();
    }

    /**
     * Whether the user holds the property's Owner role, active there or not.
     */
    public function isOwner(Property $property, User $user): bool
    {
        return $this->ownersQuery()->where('roles.property_id', $property->id)->where('users.id', $user->id)->exists();
    }

    /**
     * Whether the property keeps an Owner with an active membership other than the user given.
     */
    public function hasActiveOwnerOtherThan(Property $property, User $user): bool
    {
        return $this->ownersQuery()
            ->where('roles.property_id', $property->id)
            ->where('users.id', '<>', $user->id)
            ->whereNotNull('property_memberships.id')
            ->whereNull('property_memberships.deactivated_at')
            ->exists();
    }

    /**
     * @return Builder<User>
     */
    private function ownersQuery(): Builder
    {
        return User::query()
            ->join('model_has_roles', fn ($join) => $join
                ->on('model_has_roles.model_id', '=', 'users.id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass()))
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->leftJoin('property_memberships', fn ($join) => $join
                ->on('property_memberships.user_id', '=', 'users.id')
                ->whereColumn('property_memberships.property_id', 'roles.property_id'))
            ->where('roles.default_role', DefaultRoleEnum::Owner->value)
            ->orderByRaw('property_memberships.deactivated_at IS NOT NULL')
            ->orderBy('users.id');
    }
}
