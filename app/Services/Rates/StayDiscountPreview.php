<?php

namespace App\Services\Rates;

use App\Enums\PricingBasis;
use App\Models\PropertyAgeGroup;
use App\Models\RatePlan;
use App\Models\RatePlanStayDiscount;
use App\Models\RoomType;
use App\Services\Rates\Pricing\NightPrice;
use App\Services\Rates\Pricing\QuoteRefused;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Prices sample stays for every room type of a plan with long-stay rules that need not be saved yet. It reads only.
 * Each room type is priced once for the longest stay, then each shorter stay gets its own discount.
 */
class StayDiscountPreview
{
    /** The most stay lengths shown: 1 night and the shortest rule lengths. */
    public const MAX_STAY_LENGTHS = 4;

    /** The longest sample stay of the By night view. */
    public const MAX_NIGHTS = 14;

    public const REFUSAL_TOO_MANY_GUESTS = 'too_many_guests';

    public function __construct(
        private RateQuoteService $rateQuoteService,
        private StayDiscount $stayDiscount,
    ) {}

    /**
     * @param  Collection<int, RatePlanStayDiscount>  $rules
     * @return list<int>
     */
    public function stayLengths(Collection $rules): array
    {
        return array_values(collect([1])
            ->merge($rules->pluck('min_nights'))
            ->unique()
            ->sort()
            ->take(self::MAX_STAY_LENGTHS)
            ->values()
            ->all());
    }

    /**
     * The By night view starts at the longest rule, so every discounted night shows. With no rules it shows 2 nights.
     *
     * @param  Collection<int, RatePlanStayDiscount>  $rules
     */
    public function defaultNights(Collection $rules): int
    {
        return min(self::MAX_NIGHTS, max(2, (int) $rules->max('min_nights')));
    }

    /**
     * @param  Collection<int, RatePlanStayDiscount>  $rules
     * @param  int  $nights  The stay length of the By night view
     * @param  int|null  $adults  Null prices each room type for its guests included
     * @param  array<int, int>  $childrenByAgeGroup  Children per age group id
     * @return list<array{roomType: RoomType, adults: int, children: int, unit: string, refusal: ?RateQuote, stays: array<int, RateQuote>, nightAmounts: array<int, list<Money>>, byNight: ?array{quote: RateQuote, unitAmounts: list<Money>}}>
     */
    public function preview(RatePlan $plan, Collection $rules, CarbonImmutable $arrival, int $nights, ?int $adults, array $childrenByAgeGroup): array
    {
        $lengths = $this->stayLengths($rules);
        $childAges = $this->childAges($plan, $childrenByAgeGroup);
        $isBedPlan = $plan->pricing_basis === PricingBasis::PerBed;
        $rows = [];

        $roomTypes = $plan->roomTypes()
            ->whereNull('room_types.archived_at')
            ->orderBy('room_types.position')
            ->orderBy('room_types.id')
            ->get();

        foreach ($roomTypes as $roomType) {
            $partyAdults = $adults ?? max(1, $roomType->base_occupancy);
            $partyChildren = $adults === null ? [] : $childAges;
            $unit = match ($plan->pricing_basis) {
                PricingBasis::PerGuest => $partyChildren === [] ? 'guest' : 'room',
                PricingBasis::PerBed => 'bed',
                default => 'room',
            };
            $row = [
                'roomType' => $roomType,
                'adults' => $partyAdults,
                'children' => count($partyChildren),
                'unit' => $unit,
                'refusal' => null,
                'stays' => [],
                'nightAmounts' => [],
                'byNight' => null,
            ];

            if (! $isBedPlan && ($partyAdults > $roomType->max_adults || count($partyChildren) > $roomType->max_children)) {
                $rows[] = [...$row, 'refusal' => RateQuote::refused(self::REFUSAL_TOO_MANY_GUESTS)];

                continue;
            }

            try {
                $priced = $this->rateQuoteService->priceNights(new RateQuoteRequest(
                    $plan,
                    $roomType,
                    $arrival,
                    $arrival->addDays(max([...$lengths, $nights])),
                    $partyAdults,
                    $partyChildren,
                    $isBedPlan ? $partyAdults : 1,
                ));
            } catch (QuoteRefused $refused) {
                $rows[] = [...$row, 'refusal' => RateQuote::refused($refused->reason, $refused->date)];

                continue;
            }

            $divisor = $unit === 'room' ? 1 : $priced->discountUnits;
            $stayOf = function (int $length) use ($rules, $priced, $divisor): array {
                $discounted = $this->stayDiscount->apply($rules, array_slice($priced->nights, 0, $length), $priced->discountUnits);

                if ($discounted === []) {
                    return [RateQuote::refused(RateQuote::REFUSAL_INVALID_STAY), []];
                }

                return [
                    RateQuote::quoted($discounted),
                    array_map(fn (NightPrice $night): Money => $night->net()->dividedBy($divisor, RoundingMode::HalfUp), $discounted),
                ];
            };

            foreach ($lengths as $length) {
                [$row['stays'][$length], $row['nightAmounts'][$length]] = $stayOf($length);
            }

            [$quote, $unitAmounts] = $stayOf($nights);
            $row['byNight'] = ['quote' => $quote, 'unitAmounts' => $unitAmounts];

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One sample age per child: the lowest age of its group, so the quote maps it back to the same group.
     *
     * @param  array<int, int>  $childrenByAgeGroup
     * @return list<int>
     */
    private function childAges(RatePlan $plan, array $childrenByAgeGroup): array
    {
        if (array_sum($childrenByAgeGroup) === 0) {
            return [];
        }

        $groups = PropertyAgeGroup::query()
            ->where('property_id', $plan->property_id)
            ->whereKey(array_keys($childrenByAgeGroup))
            ->get();
        $ages = [];

        foreach ($groups as $group) {
            array_push($ages, ...array_fill(0, $childrenByAgeGroup[$group->id], $group->min_age));
        }

        return $ages;
    }
}
