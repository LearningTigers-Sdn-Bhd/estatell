<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\OnboardingBasicRatePreviewData;
use App\Data\Onboarding\OnboardingMoreRateExampleData;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\Tax;
use App\Services\Catalogues\RoomRevenueTaxIds;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Rates\DerivedRateResolver;
use App\Services\Rates\Pricing\NightPrice;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * What a guest pays for the Basic rate rows as they are on screen, before they are saved: the price with the taxes that
 * apply to rooms, for a local guest. A Per guest price is per adult, so the total is for the room type's base occupancy.
 */
class OnboardingBasicRatePreview
{
    public function __construct(private RoomRevenueTaxIds $roomRevenueTaxIds, private TaxCalculator $taxCalculator, private DerivedRateResolver $derivedRateResolver) {}

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

    /**
     * The example column of the Follows rows: the weekday price of the first room type the row sells (or the first room
     * type on its Basic Rate basis) and the price that follows from it. The change is moved up or down by an amount or a percent.
     *
     * @param  list<array{room_type_id: int, price_by: PricingBasis, weekday: string|null, weekend: string|null}>  $basicRows
     * @param  list<array{key: string, parent_basis: PricingBasis, direction: string, unit: string, amount: string|null, room_type_ids: list<int>}>  $followers
     * @return list<OnboardingMoreRateExampleData>
     */
    public function followerExamples(Property $property, array $basicRows, array $followers): array
    {
        return array_map(function (array $follower) use ($property, $basicRows): OnboardingMoreRateExampleData {
            $onBasis = array_values(array_filter($basicRows, fn (array $row): bool => $row['price_by'] === $follower['parent_basis']));
            $row = collect($onBasis)->first(fn (array $candidate): bool => in_array($candidate['room_type_id'], $follower['room_type_ids'], true)) ?? ($onBasis[0] ?? null);

            if ($row === null || ! $this->isPrice($row['weekday']) || ! $this->isPrice($follower['amount'])) {
                return new OnboardingMoreRateExampleData($follower['key'], null, null);
            }

            $currency = $property->settings->currency->getCurrencyCode();
            $zero = Money::zero($currency);
            $night = NightPrice::of(now(), Money::of($row['weekday'], $currency, roundingMode: RoundingMode::HalfUp), $zero, $zero);
            $value = number_format((float) $follower['amount'], 2, '.', '');
            $derived = new RatePlan([
                'derivation' => $follower['unit'] === 'percent' ? RateDerivation::Percent : RateDerivation::Amount,
                'derivation_value' => $follower['direction'] === 'down' ? '-'.$value : $value,
            ]);

            return new OnboardingMoreRateExampleData(
                $follower['key'],
                (string) $night->total()->getAmount(),
                (string) $this->derivedRateResolver->resolve($night, $derived, 1)->total()->getAmount(),
            );
        }, $followers);
    }

    private function isPrice(?string $amount): bool
    {
        return $amount !== null && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) === 1 && (float) $amount > 0;
    }
}
