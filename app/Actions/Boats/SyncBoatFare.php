<?php

namespace App\Actions\Boats;

use App\Actions\Reservations\AddUnitAddOn;
use App\Actions\Reservations\RemoveUnitAddOn;
use App\Data\Reservations\NewAddOnData;
use App\Models\BoatDeparture;
use App\Models\BoatTripAddOn;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use App\Services\Rates\RatePlanIncludes;

class SyncBoatFare
{
    public function __construct(
        private AddUnitAddOn $addUnitAddOn,
        private RemoveUnitAddOn $removeUnitAddOn,
        private RatePlanIncludes $ratePlanIncludes,
    ) {}

    /**
     * Replace the fare rows of one trip. Unposted rows are removed with `RemoveUnitAddOn`; a posted row is kept, and the
     * result says so. Then, when `$departure` has a fare the plan does not include, the fare is added as an undated
     * add-on through `AddUnitAddOn` and linked to the trip. Pass null to only remove (Clear).
     *
     * A refusal is returned, and the caller rolls back its transaction. Call it only with the unit row already locked by
     * the caller. See docs/24-boats/02-boat-trips.md#fare.
     */
    public function __invoke(User $actor, ReservationUnit $unit, UnitBoatTrip $trip, ?BoatDeparture $departure): BoatTripResult
    {
        $links = BoatTripAddOn::query()
            ->where('unit_boat_trip_id', $trip->id)
            ->with('unitAddOn')
            ->get();

        $postedFareKept = $links->contains(fn (BoatTripAddOn $link): bool => $link->unitAddOn->folio_line_id !== null);

        foreach ($links as $link) {
            if ($link->unitAddOn->folio_line_id !== null) {
                continue;
            }

            if (! ($this->removeUnitAddOn)($actor, $link->unitAddOn)) {
                return BoatTripResult::refused(BoatTripResult::REFUSAL_FARE_NOT_FOUND);
            }
        }

        if ($departure === null || $departure->charge_item_id === null) {
            return BoatTripResult::done($postedFareKept);
        }

        if ($unit->rate_plan_id !== null && $this->ratePlanIncludes->includesChargeItem($unit->rate_plan_id, $departure->charge_item_id)) {
            return BoatTripResult::done($postedFareKept);
        }

        $added = ($this->addUnitAddOn)($actor, $unit, new NewAddOnData(chargeItemId: $departure->charge_item_id));

        if ($added->isRefused()) {
            return BoatTripResult::refused(BoatTripResult::REFUSAL_FARE_NOT_FOUND);
        }

        foreach ($added->addOns as $addOn) {
            BoatTripAddOn::query()->create(['unit_boat_trip_id' => $trip->id, 'unit_add_on_id' => $addOn->id]);
        }

        return BoatTripResult::done($postedFareKept);
    }
}
