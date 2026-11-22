<?php

namespace App\Services\Reservations;

use App\Data\Reservations\SalesPersonOptionData;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff who can be named as the sales person of a booking: active staff users of the property. Company portal
 * users are not staff. See docs/12-reservations/12-sales-person.md.
 */
class SalesPeople
{
    /**
     * The picker list, by name, in one query.
     *
     * @return list<SalesPersonOptionData>
     */
    public function options(Property $property): array
    {
        return array_values($this->query($property)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $user): SalesPersonOptionData => SalesPersonOptionData::fromModel($user))
            ->all());
    }

    public function isAvailable(Property|int $property, int $userId): bool
    {
        return $this->query($property)->whereKey($userId)->exists();
    }

    /**
     * @return Builder<User>
     */
    private function query(Property|int $property): Builder
    {
        return User::query()
            ->activeMemberOf($property)
            ->where('is_company_user', false);
    }
}
