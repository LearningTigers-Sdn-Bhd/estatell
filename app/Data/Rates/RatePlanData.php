<?php

namespace App\Data\Rates;

use App\Data\Shared\BadgeData;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Enums\RatePlanStatus;
use App\Enums\Tone;
use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/** One row of the rate plans list, and the values of the plan editor. */
class RatePlanData extends Data
{
    /**
     * @param  list<int>  $roomTypeIds  The active room types the plan sells.
     * @param  list<string>  $roomTypeNames  The names of those room types, in display order.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public PricingBasis $pricingBasis,
        public ?int $fixedNights,
        public ?PackageUnit $packageUnit,
        public bool $usesChildPrices,
        public string $family,
        public string $pricingLabel,
        public Tone $familyTone,
        public Tone $pricingTone,
        public string $extraAdultAmount,
        public string $extraChildAmount,
        public string $singleSupplement,
        public bool $archived,
        public BadgeData $status,
        public bool $isDerived,
        public bool $checkChildPrices,
        public array $roomTypeIds,
        public array $roomTypeNames,
    ) {}

    /**
     * Load the plan's `roomTypes` first, and `parent` to show what a derived plan follows. Archived room types are left out.
     */
    public static function fromModel(RatePlan $ratePlan): self
    {
        $roomTypes = $ratePlan->roomTypes->whereNull('archived_at')->sortBy(['position', 'id'])->values();

        return new self(
            id: $ratePlan->id,
            name: $ratePlan->name,
            description: $ratePlan->description,
            pricingBasis: $ratePlan->pricing_basis,
            fixedNights: $ratePlan->fixed_nights,
            packageUnit: $ratePlan->package_unit,
            usesChildPrices: $ratePlan->pricing_basis->usesChildPrices($ratePlan->package_unit),
            family: $ratePlan->pricing_basis->family(),
            pricingLabel: self::pricingLabel($ratePlan),
            familyTone: $ratePlan->pricing_basis->familyTone(),
            pricingTone: $ratePlan->pricing_basis->tone(),
            extraAdultAmount: $ratePlan->extra_adult_amount,
            extraChildAmount: $ratePlan->extra_child_amount,
            singleSupplement: $ratePlan->single_supplement,
            archived: $ratePlan->archived_at !== null,
            status: BadgeData::fromRatePlanStatus($ratePlan->archived_at === null ? RatePlanStatus::Active : RatePlanStatus::Archived),
            isDerived: $ratePlan->parent_rate_plan_id !== null,
            checkChildPrices: $ratePlan->needs_child_price_review,
            roomTypeIds: $roomTypes->pluck('id')->all(),
            roomTypeNames: $roomTypes->pluck('name')->all(),
        );
    }

    private static function pricingLabel(RatePlan $ratePlan): string
    {
        if ($ratePlan->parent_rate_plan_id === null || ! $ratePlan->relationLoaded('parent') || $ratePlan->derivation === null) {
            return $ratePlan->pricing_basis->label();
        }

        $value = (float) $ratePlan->derivation_value;
        $change = ($value < 0 ? '-' : '+').rtrim(rtrim(number_format(abs($value), 2, '.', ''), '0'), '.')
            .($ratePlan->derivation === RateDerivation::Percent ? '%' : '');

        return __('Follows :parent :change', ['parent' => $ratePlan->parent->name, 'change' => $change]);
    }
}
