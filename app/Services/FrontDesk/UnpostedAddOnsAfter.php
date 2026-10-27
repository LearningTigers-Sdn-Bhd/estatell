<?php

namespace App\Services\FrontDesk;

use App\Enums\ChargingUnit;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class UnpostedAddOnsAfter
{
    /**
     * The add-on rows a stay shortened to `$departure` no longer uses: unposted rows dated on or after the departure
     * (nightly items) or after it (other items, whose date may be the departure date). A posted add-on is never one.
     * `ProcessEarlyDeparture` and `CheckOutAsDayUse` delete them; the day use preview lists them.
     *
     * @return Builder<UnitAddOn>
     */
    public function query(ReservationUnit $unit, CarbonInterface $departure): Builder
    {
        $nightly = [ChargingUnit::PerNight->value, ChargingUnit::PerGuestNight->value];
        $date = $departure->toDateString();

        return UnitAddOn::query()
            ->where('reservation_unit_id', $unit->id)
            ->whereNull('folio_line_id')
            ->whereNotNull('service_date')
            ->where(fn ($query) => $query
                ->where(fn ($nightlyRows) => $nightlyRows->whereDate('service_date', '>=', $date)->whereHas('chargeItem', fn ($item) => $item->whereIn('charging_unit', $nightly)))
                ->orWhereDate('service_date', '>', $date));
    }
}
