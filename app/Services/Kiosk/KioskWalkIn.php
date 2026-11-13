<?php

namespace App\Services\Kiosk;

use App\Data\Kiosk\KioskWalkInData;
use App\Enums\BookingSourceType;
use App\Enums\CompanyAccess;
use App\Enums\SellUnit;
use App\Models\BookingSource;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\AutoAddOns;
use App\Services\Reservations\BookingQuote;
use Brick\Math\BigDecimal;
use Brick\Money\Money;

class KioskWalkIn
{
    public function __construct(private BookingQuote $quote, private AutoAddOns $autoAddOns, private CurrentBusinessDate $businessDate) {}

    /** @return list<KioskWalkInOffer> */
    public function offers(Property $property, KioskWalkInData $search, ?int $roomTypeId = null): array
    {
        return $property->settings->kiosk_walk_in_enabled ? $this->available($property, $search, $roomTypeId) : [];
    }

    /**
     * The rooms a walk-in can book from the property's own rates, whichever channel asks (the kiosk or the Concierge
     * page); each channel checks its own switch first. Nothing on a day before the business date.
     *
     * @return list<KioskWalkInOffer>
     */
    public function available(Property $property, KioskWalkInData $search, ?int $roomTypeId = null): array
    {
        $date = $this->businessDate->date($property);
        if ($date === null || $search->arrival->lt($date)) {
            return [];
        }
        $types = RoomType::query()->where('property_id', $property->id)->active()
            ->where('max_adults', '>=', $search->adults)->where('max_children', '>=', count($search->childAges))
            ->when($roomTypeId !== null, fn ($query) => $query->whereKey($roomTypeId))
            ->with(['ratePlans' => fn ($plans) => $plans->where('rate_plans.property_id', $property->id)->active()->nightly()->where('company_access', CompanyAccess::Everyone)->ordered()])->ordered()->get();
        $offers = [];
        $addOns = $this->autoAddOns->forUnit($property);

        foreach ($types as $type) {
            if ($type->sell_unit === SellUnit::Bed && ($search->adults !== 1 || $search->childAges !== [])) {
                continue;
            }
            $best = null;
            foreach ($type->ratePlans as $plan) {
                $quote = $this->quote->line($property, $type->id, $plan->id, $search->arrival, $search->arrival->addDays($search->nights), $search->adults, $search->childAges, 1, $addOns);
                if ($quote->errors !== [] || $quote->violations !== []) {
                    continue;
                }
                $total = Money::of(BigDecimal::of((string) $quote->lineTotal)->plus($quote->addOnsTotal), $property->settings->currency);
                if ($best === null || $total->isLessThan($best->total)) {
                    $best = new KioskWalkInOffer($type, $plan, $total, $quote->available > 0);
                }
            }
            if ($best !== null) {
                $offers[] = $best;
            }
        }

        return $offers;
    }

    public function source(Property $property): ?BookingSource
    {
        return BookingSource::query()->where('property_id', $property->id)->where('type', BookingSourceType::WalkIn)->active()->first();
    }
}
