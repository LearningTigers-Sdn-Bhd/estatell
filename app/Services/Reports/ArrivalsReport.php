<?php

namespace App\Services\Reports;

use App\Data\Reports\ArrivalRow;
use App\Data\Reports\ReportFilters;
use App\Enums\BoatDirection;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Boats\BoatTimeLabel;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Pennant\Feature;

/**
 * The reservation units that arrive in the date range, by arrival date. Units that hold inventory count: Confirmed
 * (still to arrive), Checked in and Checked out. Cancelled and no-show units are left out because nobody arrives.
 */
class ArrivalsReport implements Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
        private BoatTimeLabel $boatTimes,
    ) {}

    /**
     * The units that arrive in the range, without order or relations. The Guest list counts them with it.
     *
     * @return Builder<ReservationUnit>
     */
    public function query(Property $property, ReportFilters $filters): Builder
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereBetween('arrival_date', [$filters->from, $filters->to])
            ->whereIn('status', UnitStatus::holdingInventory());
    }

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<ArrivalRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $boats = Feature::for($property)->active(Boats::class);

        return $this->unitRows->rows(
            $this->query($property, $filters)
                ->orderBy('arrival_date')
                ->orderBy('arrival_time')
                ->when($boats, fn ($query) => $query->with('boatTrips'))
                ->orderBy('id'),
            $rowKeys,
            fn (array $unitIds): array => $this->roomBox->assignmentsForUnits($unitIds),
            fn (ReservationUnit $unit, ?string $room, string $balance): ArrivalRow => ArrivalRow::fromModel($unit, $room, $balance, $boats ? $this->boatTimes->forUnit($unit, BoatDirection::In) : null),
        );
    }
}
