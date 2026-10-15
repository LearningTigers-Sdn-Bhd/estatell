<?php

namespace App\Services\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff of a property who have never completed their first sign-in: they still hold the temp password they were
 * given. Owners and deactivated users are left out. Go-live refreshes these temp passwords, and the wizard counts them.
 */
class PendingSignInUsers
{
    /**
     * @return Builder<User>
     */
    public function query(Property $property): Builder
    {
        return User::query()
            ->where('property_id', $property->id)
            ->where('must_change_password', true)
            ->whereNull('deactivated_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass())
                ->where('roles.default_role', DefaultRoleEnum::Owner->value));
    }

    public function count(Property $property): int
    {
        return $this->query($property)->count();
    }
}
