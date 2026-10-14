<?php

namespace App\Services\CityLedger;

use App\Data\CityLedger\CompanyOptionData;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Property;

/**
 * The companies a booking can name. The list is bounded, so it loads whole and the combobox filters it in the browser.
 */
class CompanyOptions
{
    /**
     * The Active companies of the property in name order.
     *
     * @return list<CompanyOptionData>
     */
    public function active(Property $property): array
    {
        return array_values(Company::query()
            ->where('property_id', $property->id)
            ->where('status', CompanyStatus::Active)
            ->orderBy('name')
            ->get()
            ->map(CompanyOptionData::fromModel(...))
            ->all());
    }
}
