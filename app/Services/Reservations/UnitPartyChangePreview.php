<?php

namespace App\Services\Reservations;

use App\Enums\BookingSourceType;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use Brick\Math\BigDecimal;

/**
 * Plans a change of a unit's guests (adults and child ages): the whole stay is quoted again for the new party, so the
 * long-stay discount still counts every night. A night not charged yet takes the new price, unless staff set its price
 * by hand. A charged night takes it only with `$repriceCharged`. A channel booking keeps its OTA price. The charged
 * per-guest add-ons to correct come from `PostedAddOnAdjustments`. A channel revision (`$channelRevision`) is never
 * refused for too many guests, because the OTA booking is already sold. It writes nothing. See docs/12-reservations/14-change-guests.md.
 */
class UnitPartyChangePreview
{
    public const REFUSAL_UNIT_NOT_OPEN = 'unit_not_open';

    public const REFUSAL_DAY_USE = 'party_day_use';

    public const REFUSAL_BED_UNIT = 'party_bed_unit';

    public const REFUSAL_TOO_MANY_GUESTS = 'party_too_many_guests';

    public const REFUSAL_NOTHING_CHANGED = 'party_nothing_changed';

    public function __construct(
        private RateQuoteService $rateQuoteService,
        private PostedAddOnAdjustments $postedAddOnAdjustments,
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
    ) {}

    /**
     * @param  list<int>  $childAges
     */
    public function plan(ReservationUnit $unit, int $adults, array $childAges, bool $repriceCharged, bool $channelRevision = false): UnitPartyPlan
    {
        $unit->loadMissing(['roomType', 'ratePlan', 'reservation.bookingSource', 'childAges']);
        sort($childAges);

        if (! in_array($unit->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)) {
            return UnitPartyPlan::refused(self::REFUSAL_UNIT_NOT_OPEN);
        }

        if ($unit->stay_type === StayType::DayUse) {
            return UnitPartyPlan::refused(self::REFUSAL_DAY_USE);
        }

        if ($unit->roomType->sell_unit === SellUnit::Bed) {
            return UnitPartyPlan::refused(self::REFUSAL_BED_UNIT);
        }

        if ($adults < 1 || (! $channelRevision && ($adults > $unit->roomType->max_adults || count($childAges) > $unit->roomType->max_children))) {
            return UnitPartyPlan::refused(self::REFUSAL_TOO_MANY_GUESTS);
        }

        $currentAges = $unit->childAges->pluck('age')->map(fn (mixed $age): int => (int) $age)->sort()->values()->all();

        if ($adults === $unit->adults && $childAges === $currentAges) {
            return UnitPartyPlan::refused(self::REFUSAL_NOTHING_CHANGED);
        }

        $nights = $unit->nights()->orderBy('date')->get();
        $charged = FolioLine::query()
            ->whereIn('idempotency_key', $nights->map(fn (UnitNight $night): string => 'night:'.$night->id)->all())
            ->pluck('idempotency_key')
            ->flip();
        $fromChannel = $unit->reservation->bookingSource?->type === BookingSourceType::Ota;
        $prices = [];

        if (! $fromChannel && $nights->isNotEmpty()) {
            $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
                $unit->ratePlan,
                $unit->roomType,
                $unit->arrival_date,
                $unit->departure_date,
                $adults,
                $childAges,
            ));

            if ($quote->isRefused()) {
                return UnitPartyPlan::refused((string) $quote->refusal);
            }

            foreach ($quote->nights as $price) {
                $prices[$price->date->toDateString()] = $price;
            }
        }

        $rows = [];
        $before = BigDecimal::zero();
        $after = BigDecimal::zero();

        foreach ($nights as $night) {
            $price = $prices[$night->date->toDateString()] ?? null;
            $isCharged = $charged->has('night:'.$night->id);
            $overridden = $night->override_amount !== null;
            $changes = $price !== null && ! $overridden && (! $isCharged || $repriceCharged)
                && ! BigDecimal::of((string) $price->net()->getAmount())->isEqualTo($night->amount);
            $rows[] = ['night' => $night, 'price' => $price, 'charged' => $isCharged, 'overridden' => $overridden, 'changes' => $changes];
            $before = $before->plus($night->amount);
            $after = $after->plus($changes ? (string) $price->net()->getAmount() : $night->amount);
        }

        $businessDate = $this->currentBusinessDate->date($unit->property) ?? $this->propertyToday->date($unit->property);
        $postedAddOns = $this->postedAddOnAdjustments->plan($unit, $adults, $childAges, $businessDate);

        return UnitPartyPlan::planned($unit, $adults, $childAges, $rows, $fromChannel, $repriceCharged, (string) $before->toScale(2), (string) $after->toScale(2), $postedAddOns);
    }
}
