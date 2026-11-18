<?php

namespace App\Services\Concierge;

use App\Data\Kiosk\KioskWalkInData;
use App\Enums\CompanyAccess;
use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Availability\DayUseAvailability;
use App\Services\Kiosk\KioskWalkInOffer;
use App\Services\Property\PropertyToday;
use App\Services\Rates\DayUseOffers;
use App\Services\Rates\RestrictionCheck;
use Brick\Money\Money;

/**
 * The day use rooms a guest can book from the Concierge lobby page: a room sold per room, a Day use plan that prices the
 * hours on the date, no stop sell, and a room free for the block. A guest may not start a block that has begun, so the
 * plan's window and hour limits always apply. The cheapest plan wins per room type, and the offer holds the first free
 * room. See docs/12-reservations/10-day-use.md and docs/30-concierge/01-overview.md#day-use.
 */
class ConciergeDayUse
{
    public function __construct(private DayUseOffers $offers, private DayUseAvailability $availability, private RestrictionCheck $restrictions, private PropertyToday $today) {}

    /**
     * Whether the property sells day use to guests at all: an active room type sold per room and an active Day use plan for it.
     */
    public function isSold(Property $property): bool
    {
        return RoomType::query()->where('property_id', $property->id)->active()->where('sell_unit', SellUnit::Room)
            ->whereHas('ratePlans', fn ($plans) => $plans->where('rate_plans.property_id', $property->id)->active()->dayUse()->where('company_access', CompanyAccess::Everyone))
            ->exists();
    }

    /**
     * Whether a start time on the date is still ahead in the property's time zone.
     */
    public function isFuture(Property $property, KioskWalkInData $search): bool
    {
        return $search->startTime !== null && ! $this->offers->isLate($property, $search->arrival, $search->startTime);
    }

    /**
     * @return list<KioskWalkInOffer>
     */
    public function available(Property $property, KioskWalkInData $search, ?int $roomTypeId = null): array
    {
        if (! $search->isDayUse() || ! $this->isFuture($property, $search) || $this->offers->enteredRefusal((string) $search->startTime, (int) $search->hours) !== null) {
            return [];
        }

        $endsAt = (string) DayUseOffers::endsAt((string) $search->startTime, (int) $search->hours);
        $types = RoomType::query()->where('property_id', $property->id)->active()->where('sell_unit', SellUnit::Room)
            ->where('max_adults', '>=', $search->adults)->where('max_children', '>=', count($search->childAges))
            ->when($roomTypeId !== null, fn ($query) => $query->whereKey($roomTypeId))
            ->with(['ratePlans' => fn ($plans) => $plans->where('rate_plans.property_id', $property->id)->active()->dayUse()->where('company_access', CompanyAccess::Everyone)->ordered()])->ordered()->get();
        $offers = [];

        foreach ($types as $type) {
            $room = $this->availability->freeRooms($type, $search->arrival, (string) $search->startTime, $endsAt)[0] ?? null;
            $best = null;

            foreach ($type->ratePlans as $plan) {
                $hour = array_find($this->offers->forStart($plan, $type, $search->arrival, (string) $search->startTime), fn ($offer): bool => $offer->hours === $search->hours);

                if ($hour === null || $this->restrictions->dayUseViolations($plan->id, $type->id, $search->arrival) !== []) {
                    continue;
                }

                $total = Money::of($hour->amount, $property->settings->currency);

                if ($best === null || $total->isLessThan($best->total)) {
                    $best = new KioskWalkInOffer($type, $plan, $total, $room !== null, $room?->id);
                }
            }

            if ($best !== null) {
                $offers[] = $best;
            }
        }

        return $offers;
    }
}
