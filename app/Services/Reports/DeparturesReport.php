<?php

namespace App\Services\Reports;

use App\Data\Reports\DepartureRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Enums\BoatDirection;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Boats\BoatTimeLabel;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Pennant\Feature;

/**
 * The reservation units that depart in the date range, by departure date. The same units count as in Arrivals: the
 * ones that hold inventory. Cancelled and no-show units are left out because nobody departs.
 */
class DeparturesReport implements HasTotals, Report
{
    public function __construct(
        private UnitReportRows $unitRows,
        private ReservationRoomBox $roomBox,
        private BoatTimeLabel $boatTimes,
    ) {}

    /**
     * The units that depart in the range, without order or relations. The Guest list counts them with it. With `boatNotSet`, only the overnight units with no Boat out trip.
     *
     * @return Builder<ReservationUnit>
     */
    public function query(Property $property, ReportFilters $filters): Builder
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereBetween('departure_date', [$filters->from, $filters->to])
            ->whereIn('status', UnitStatus::holdingInventory())
            ->when($filters->boatNotSet, fn (Builder $query) => $query
                ->where('stay_type', StayType::Overnight->value)
                ->whereDoesntHave('boatTrips', fn (Builder $trips) => $trips->where('direction', BoatDirection::Out->value)));
    }

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<DepartureRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $boats = Feature::for($property)->active(Boats::class);

        return $this->unitRows->rows(
            $this->query($property, $filters)
                ->orderBy('departure_date')
                ->orderBy('departure_time')
                ->when($boats, fn ($query) => $query->with('boatTrips'))
                ->orderBy('id'),
            $rowKeys,
            fn (array $unitIds): array => $this->roomBox->lastNightAssignmentsForUnits($unitIds),
            fn (ReservationUnit $unit, ?string $room, string $balance): DepartureRow => DepartureRow::fromModel($unit, $room, $balance, $boats ? $this->boatTimes->forUnit($unit, BoatDirection::Out) : null),
        );
    }

    /**
     * The sums of nights, adults and children, and the balance of each reservation once.
     *
     * @param  list<DepartureRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'reservation' => null,
            'guest' => null,
            'phone' => null,
            'departure' => null,
            'departure_time' => null,
            'boat' => null,
            'unit' => null,
            'room_type' => null,
            'room' => null,
            'guests' => null,
            'company' => null,
            'status' => null,
            ...$this->unitRows->totalFigures($rows),
        ]);
    }
}
