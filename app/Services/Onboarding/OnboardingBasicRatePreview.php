<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\OnboardingBasicRatePreviewData;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Tax;
use App\Services\Catalogues\RoomRevenueTaxIds;
use App\Services\Catalogues\TaxCalculator;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * What a guest pays for the Basic rate rows as they are on screen, before they are saved: the price with the taxes that
 * apply to rooms, for a local guest. A Per guest price is per adult, so the total is for the room type's base occupancy.
 */
class OnboardingBasicRatePreview
{
    public function __construct(private RoomRevenueTaxIds $roomRevenueTaxIds, private TaxCalculator $taxCalculator) {}

    /**
     * @param  list<array{room_type_id: int, price_by: PricingBasis, weekday: string|null, weekend: string|null}>  $rows
     * @return list<OnboardingBasicRatePreviewData>
     */
    public function forRows(Property $property, array $rows): array
    {
        $taxes = $property->taxes()->whereKey($this->roomRevenueTaxIds->forRoom($property))->get();
        $roomTypes = $property->roomTypes()->whereKey(array_column($rows, 'room_type_id'))->get()->keyBy('id');

        return array_values(array_filter(array_map(function (array $row) use ($property, $taxes, $roomTypes): ?OnboardingBasicRatePreviewData {
            $roomType = $roomTypes->get($row['room_type_id']);

            if (! $roomType instanceof RoomType) {
                return null;
            }

            $adults = $row['price_by'] === PricingBasis::PerGuest ? max(1, $roomType->base_occupancy) : 1;

            return new OnboardingBasicRatePreviewData(
                roomTypeId: $roomType->id,
                adults: $adults,
                weekdayTotal: $this->total($property, $taxes, $row['weekday'], $adults),
                weekendTotal: $this->total($property, $taxes, $row['weekend'], $adults),
            );
        }, $rows)));
    }

    /**
     * @param  Collection<int, Tax>  $taxes
     */
    private function total(Property $property, Collection $taxes, ?string $amount, int $adults): ?string
    {
        if ($amount === null || preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) !== 1) {
            return null;
        }

        $entered = Money::of($amount, $property->settings->currency->getCurrencyCode(), roundingMode: RoundingMode::HalfUp)->multipliedBy($adults);
        $result = $this->taxCalculator->calculateWithTaxes($property, $taxes, $entered, '1', $property->country_code);

        return $result->grossAmount === null ? null : (string) $result->grossAmount->getAmount();
    }
}
