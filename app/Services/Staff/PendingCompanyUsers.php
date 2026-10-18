<?php

namespace App\Services\Staff;

use App\Enums\CompanyUserStatus;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The company users with an Active link at a property who have never completed their first sign-in: they still hold the
 * temp password they were given. Users also linked at another property are left out, because the other property could
 * be locked out by a new password (the same rule as the Reset password button). Deactivated users are left out.
 */
class PendingCompanyUsers
{
    /**
     * @return Builder<User>
     */
    public function query(Property $property): Builder
    {
        return User::query()
            ->where('is_company_user', true)
            ->where('must_change_password', true)
            ->whereNull('deactivated_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('company_users')
                ->whereColumn('company_users.user_id', 'users.id')
                ->where('company_users.property_id', $property->id)
                ->where('company_users.status', CompanyUserStatus::Active->value))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('company_users')
                ->whereColumn('company_users.user_id', 'users.id')
                ->where('company_users.property_id', '<>', $property->id)
                ->where('company_users.status', CompanyUserStatus::Active->value));
    }

    public function count(Property $property): int
    {
        return $this->query($property)->count();
    }
}
