<?php

namespace App\Services\Kiosk;

use App\Data\Kiosk\KioskWalkInData;
use App\Enums\BookingSourceType;
use App\Enums\CompanyAccess;
use App\Enums\SellUnit;
use App\Models\BookingSource;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Concierge\ConciergeDayUse;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\AutoAddOns;
use App\Services\Reservations\BookingQuote;
use Brick\Math\BigDecimal;
use Brick\Money\Money;

class KioskWalkIn
{
    public function __construct(private BookingQuote $quote, private AutoAddOns $autoAddOns, private CurrentBusinessDate $businessDate, private ConciergeDayUse $dayUse) {}

    /** @return list<KioskWalkInOffer> */
    public function offers(Property $property, KioskWalkInData $search, ?int $roomTypeId = null): array
    {
        return $property->settings->kiosk_walk_in_enabled ? $this->available($property, $search, $roomTypeId) : [];
    }

    /**
     * The rooms a walk-in can book from the property's own rates, whichever channel asks (the kiosk or the Concierge
     * page); each channel checks its own switch first. Nothing on a day before the business date. Each room type gets its
     * cheapest plan, or only `$ratePlanId` when a quote names one.
     *
     * @return list<KioskWalkInOffer>
     */
    public function available(Property $property, KioskWalkInData $search, ?int $roomTypeId = null, ?int $ratePlanId = null): array
    {
        if (! $this->isOpenFor($property, $search)) {
            return [];
        }
        if ($search->isDayUse()) {
            return $this->dayUse->available($property, $search, $roomTypeId);
        }
        $cheapest = [];

        foreach ($this->priced($property, $search, $roomTypeId, $ratePlanId, onlineOnly: false) as $offer) {
            $best = $cheapest[$offer->roomType->id] ?? null;
            if ($best === null || $offer->total->isLessThan($best->total)) {
                $cheapest[$offer->roomType->id] = $offer;
            }
        }

        return array_values($cheapest);
    }

    /**
     * Every room type and plan a quote link may sell for a night stay: plans bookable online only, each priced as the
     * booking will price it, so the quote shows what the folio will charge. Nothing on a day before the business date.
     * See docs/34-ai-assistant/03-places-and-routes.md#quote-links.
     *
     * @return list<KioskWalkInOffer>
     */
    public function quotable(Property $property, KioskWalkInData $search, ?int $roomTypeId = null, ?int $ratePlanId = null): array
    {
        if (! $this->isOpenFor($property, $search) || $search->isDayUse()) {
            return [];
        }

        return $this->priced($property, $search, $roomTypeId, $ratePlanId, onlineOnly: true);
    }

    private function isOpenFor(Property $property, KioskWalkInData $search): bool
    {
        $date = $this->businessDate->date($property);

        return $date !== null && ! $search->arrival->lt($date);
    }

    /**
     * One offer per room type and plan that can be priced, in room type and plan order.
     *
     * @return list<KioskWalkInOffer>
     */
    private function priced(Property $property, KioskWalkInData $search, ?int $roomTypeId, ?int $ratePlanId, bool $onlineOnly): array
    {
        $types = RoomType::query()->where('property_id', $property->id)->active()
            ->where('max_adults', '>=', $search->adults)->where('max_children', '>=', count($search->childAges))
            ->when($roomTypeId !== null, fn ($query) => $query->whereKey($roomTypeId))
            ->with(['ratePlans' => fn ($plans) => $plans->where('rate_plans.property_id', $property->id)
                ->when($onlineOnly, fn ($plans) => $plans->bookableOnline(), fn ($plans) => $plans->active()->nightly()->where('company_access', CompanyAccess::Everyone))
                ->when($ratePlanId !== null, fn ($plans) => $plans->where('rate_plans.id', $ratePlanId))
                ->ordered()])->ordered()->get();
        $offers = [];
        $addOns = $this->autoAddOns->forUnit($property);

        foreach ($types as $type) {
            if ($type->sell_unit === SellUnit::Bed && ($search->adults !== 1 || $search->childAges !== [])) {
                continue;
            }
            foreach ($type->ratePlans as $plan) {
                $quote = $this->quote->line($property, $type->id, $plan->id, $search->arrival, $search->arrival->addDays($search->nights), $search->adults, $search->childAges, 1, $addOns);
                if ($quote->errors !== [] || $quote->violations !== []) {
                    continue;
                }
                $total = Money::of(BigDecimal::of((string) $quote->lineTotal)->plus($quote->addOnsTotal), $property->settings->currency);
                $offers[] = new KioskWalkInOffer($type, $plan, $total, $quote->available > 0);
            }
        }

        return $offers;
    }

    public function source(Property $property): ?BookingSource
    {
        return BookingSource::query()->where('property_id', $property->id)->where('type', BookingSourceType::WalkIn)->active()->first();
    }
}
