<?php

namespace App\Data\Boats;

use App\Data\Catalogues\ChargeItemChildPriceData;
use App\Data\Catalogues\ChargeItemTaxData;
use App\Enums\AgeBandPricing;
use App\Enums\ChargingUnit;
use App\Models\ChargeItem;
use App\Models\PropertyAgeGroup;
use App\Models\Tax;
use Brick\Money\Currency;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * One row of the Fares tab and the values of its fare Sheet. Times and money are strings as stored.
 */
class BoatFareRowData extends Data
{
    /**
     * @param  list<ChargeItemTaxData>  $taxes
     * @param  list<ChargeItemChildPriceData>  $childPrices
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public ?string $amount,
        public bool $allowOverride,
        public bool $active,
        public ChargingUnit $chargingUnit,
        public int $usedBy,
        public string $childrenSummary,
        #[DataCollectionOf(ChargeItemTaxData::class)]
        public array $taxes,
        #[DataCollectionOf(ChargeItemChildPriceData::class)]
        public array $childPrices,
    ) {}

    /**
     * The fare's taxes, age group prices and the count of available departures that use it. The item needs its
     * transaction code, applicable taxes and child prices loaded.
     *
     * @param  Collection<int, PropertyAgeGroup>  $ageGroups  Ordered as the Children rows are shown.
     */
    public static function fromModel(ChargeItem $chargeItem, int $usedBy, Collection $ageGroups, Currency $currency): self
    {
        $prices = $chargeItem->childPrices->keyBy('property_age_group_id');
        $childPrices = array_values($ageGroups
            ->map(fn (PropertyAgeGroup $ageGroup): ChargeItemChildPriceData => ChargeItemChildPriceData::forAgeGroup($chargeItem, $ageGroup, $prices->get($ageGroup->id)))
            ->all());

        return new self(
            id: $chargeItem->id,
            name: $chargeItem->name,
            code: $chargeItem->transactionCode->code,
            amount: $chargeItem->default_amount,
            allowOverride: $chargeItem->allow_override,
            active: $chargeItem->active,
            chargingUnit: $chargeItem->charging_unit,
            usedBy: $usedBy,
            childrenSummary: self::childrenSummary($childPrices, $currency),
            taxes: array_values($chargeItem->transactionCode->applicableTaxes
                ->map(fn (Tax $tax): ChargeItemTaxData => ChargeItemTaxData::fromModel($tax))
                ->values()
                ->all()),
            childPrices: $childPrices,
        );
    }

    /**
     * "Same as adult" when every age group is Percent 100. Otherwise each group, for example "Child 4–11 50% · Infant 0–3 Free".
     *
     * @param  list<ChargeItemChildPriceData>  $childPrices
     */
    private static function childrenSummary(array $childPrices, Currency $currency): string
    {
        $allAdult = collect($childPrices)->every(fn (ChargeItemChildPriceData $price): bool => $price->pricing === AgeBandPricing::Percent && (float) $price->value === 100.0);

        if ($allAdult) {
            return __('Same as adult');
        }

        return collect($childPrices)
            ->map(fn (ChargeItemChildPriceData $price): string => trim("{$price->label} {$price->minAge}–{$price->maxAge} ".self::priceText($price, $currency)))
            ->implode(' · ');
    }

    private static function priceText(ChargeItemChildPriceData $price, Currency $currency): string
    {
        return match ($price->pricing) {
            AgeBandPricing::Free => __('Free'),
            AgeBandPricing::Percent => rtrim(rtrim(number_format((float) $price->value, 2, '.', ''), '0'), '.').'%',
            AgeBandPricing::Fixed => $currency->getCurrencyCode().' '.number_format((float) $price->value, 2),
        };
    }
}
