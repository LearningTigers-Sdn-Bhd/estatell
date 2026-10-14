<?php

namespace App\Services\Platform;

use App\Models\Partner;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The properties a partner brought. Every read is scoped by `properties.partner_id`, so another partner's
 * property, or a direct one, is never found.
 */
class PartnerProperties
{
    public const int PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, Property>
     */
    public function paginate(Partner $partner): LengthAwarePaginator
    {
        return $this->query($partner)
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * The partner's property with this code, or a 404.
     */
    public function findByCode(Partner $partner, string $code): Property
    {
        return $this->query($partner)->where('code', strtoupper($code))->firstOrFail();
    }

    /**
     * @return Builder<Property>
     */
    private function query(Partner $partner): Builder
    {
        return Property::query()->where('properties.partner_id', $partner->id);
    }
}
