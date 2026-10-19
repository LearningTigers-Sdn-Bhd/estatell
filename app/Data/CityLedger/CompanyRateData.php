<?php

namespace App\Data\CityLedger;

use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/**
 * One row of the company's read-only Rates tab: a rate plan the company can use and where.
 */
class CompanyRateData extends Data
{
    public function __construct(
        public int $ratePlanId,
        public string $name,
        public string $pricingLabel,
        public bool $atDesk,
        public bool $inPortal,
    ) {}

    public static function fromModel(RatePlan $ratePlan, string $pricingLabel, bool $atDesk, bool $inPortal): self
    {
        return new self($ratePlan->id, $ratePlan->name, $pricingLabel, $atDesk, $inPortal);
    }
}
