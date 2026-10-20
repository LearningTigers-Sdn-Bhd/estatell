<?php

namespace App\Data\Reservations;

use App\Enums\CompanyAccess;
use App\Enums\CompanyRateMarket;
use App\Enums\PricingBasis;
use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/**
 * A rate plan to pick in the Add unit Sheet, with the room types it is sold for and who can use it at the front desk (`companyAccess`, `companyAtDesk` for All companies, `companyIds` for Only these companies, `companyMarket` and `marketCompanyIds` for a plan limited to local or international companies). `fixedNights` is set for a package, which sells a fixed stay length. A Day use plan has `isDayUse` and its hours range and Sold between window (`H:i`), and sells only day use lines; the other plans sell only overnight lines. Expects `roomTypes` to be loaded.
 */
class RatePlanOptionData extends Data
{
    /**
     * @param  list<int>  $roomTypeIds
     * @param  list<int>  $companyIds  For Only these companies, the listed companies with Front desk on.
     * @param  list<int>  $marketCompanyIds  For a plan limited to a market, the companies of that market. Empty when the plan is open to every market.
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $roomTypeIds,
        public ?int $fixedNights,
        public CompanyAccess $companyAccess = CompanyAccess::Everyone,
        public bool $companyAtDesk = true,
        public array $companyIds = [],
        public CompanyRateMarket $companyMarket = CompanyRateMarket::All,
        public array $marketCompanyIds = [],
        public bool $isDayUse = false,
        public ?int $dayUseMinHours = null,
        public ?int $dayUseMaxHours = null,
        public ?string $dayUseFrom = null,
        public ?string $dayUseUntil = null,
    ) {}

    /**
     * @param  list<int>  $marketCompanyIds  The ids of the companies in the plan's market. Ignored when the plan is open to every market.
     */
    public static function fromModel(RatePlan $ratePlan, array $marketCompanyIds = []): self
    {
        return new self(
            $ratePlan->id,
            $ratePlan->name,
            array_values($ratePlan->roomTypes->pluck('id')->map(intval(...))->all()),
            $ratePlan->pricing_basis === PricingBasis::Package ? $ratePlan->fixed_nights : null,
            $ratePlan->company_access,
            $ratePlan->company_at_desk,
            array_values($ratePlan->companies->filter(fn ($company): bool => (bool) $company->pivot->at_desk)->pluck('id')->map(intval(...))->all()),
            $ratePlan->company_market,
            $ratePlan->company_market === CompanyRateMarket::All ? [] : $marketCompanyIds,
            $ratePlan->pricing_basis === PricingBasis::DayUse,
            $ratePlan->day_use_min_hours,
            $ratePlan->day_use_max_hours,
            $ratePlan->day_use_from === null ? null : substr($ratePlan->day_use_from, 0, 5),
            $ratePlan->day_use_until === null ? null : substr($ratePlan->day_use_until, 0, 5),
        );
    }
}
