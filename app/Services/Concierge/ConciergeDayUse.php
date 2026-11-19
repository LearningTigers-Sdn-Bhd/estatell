<?php

namespace App\Services\Concierge;

use App\Data\Kiosk\KioskWalkInData;
use App\Enums\CompanyAccess;
use App\Enums\HousekeepingStatus;
use App\Enums\SellUnit;
use App\Models\DoorLock;
use App\Models\Property;
use App\Models\RoomStatus;
use App\Models\RoomType;
use App\Services\Availability\DayUseAvailability;
use App\Services\Kiosk\KioskWalkInOffer;
use App\Services\Property\PropertyToday;
use App\Services\Rates\DayUseOffers;
use App\Services\Rates\RestrictionCheck;
use Brick\Money\Money;

/**
 * The day use rooms a guest can book from the Concierge lobby page: a room sold per room, a Day use plan that prices the
 * hours on the date, no stop sell, and a room free for the block. A guest may start in the half hour that is running
 * now (at 15:02, a 15:00 start), so a room freed at 15:00 sells at once; an earlier start is refused. The plan's window
 * and hour limits always apply. The cheapest plan wins per room type, and the offer holds the first free
 * room, one with a door lock linked first. A Dirty room is never offered: no staff member is there to check it, and an
 * early day use check-out frees its room at once. See docs/12-reservations/10-day-use.md and docs/30-concierge/01-overview.md#day-use.
 */
class ConciergeDayUse
{
    public function __construct(private DayUseOffers $offers, private DayUseAvailability $availability, private RestrictionCheck $restrictions, private PropertyToday $propertyToday) {}

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
     * Whether a start time on the date is still ahead in the property's time zone, or is in the half hour running now.
     */
    public function isFuture(Property $property, KioskWalkInData $search): bool
    {
        if ($search->startTime === null) {
            return false;
        }

        if (! $this->offers->isLate($property, $search->arrival, $search->startTime)) {
            return true;
        }

        $now = $this->propertyToday->now($property);

        return $search->arrival->toDateString() === $now->toDateString()
            && DayUseOffers::minutes($search->startTime) > $now->hour * 60 + $now->minute - 30;
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
        $locked = DoorLock::query()->where('property_id', $property->id)->whereNotNull('room_id')->pluck('room_id')->map(fn (mixed $id): int => (int) $id)->all();
        $dirty = RoomStatus::query()->where('property_id', $property->id)->where('status', HousekeepingStatus::Dirty->value)->pluck('room_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ($types as $type) {
            $free = array_values(array_filter($this->availability->freeRooms($type, $search->arrival, (string) $search->startTime, $endsAt), fn ($room): bool => ! in_array($room->id, $dirty, true)));
            $room = array_find($free, fn ($room): bool => in_array($room->id, $locked, true)) ?? $free[0] ?? null;
            $best = null;

            foreach ($type->ratePlans as $plan) {
                $hour = array_find($this->offers->forStart($plan, $type, $search->arrival, (string) $search->startTime), fn ($offer): bool => $offer->hours === $search->hours);

                if ($hour === null || $this->offers->refusal($plan, (string) $search->startTime, (int) $search->hours) !== null || $this->restrictions->dayUseViolations($plan->id, $type->id, $search->arrival) !== []) {
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
