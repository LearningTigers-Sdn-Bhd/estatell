<?php

namespace App\Actions\Boats;

use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use App\Services\Boats\DateSailing;
use App\Services\Boats\DateSailings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ResetDepartureDate
{
    public function __construct(
        private DateSailings $dateSailings,
        private BoatTripUsage $boatTripUsage,
        private SetSailingUnitTimes $setUnitTimes,
    ) {}

    /**
     * Default: delete a departure's stored sailings for a date, so the date runs as the timetable again. Refused while an
     * added boat has guests (`sailing_has_guests`) or the date is before the business date (`date_closed`). The guests of
     * the main sailing stay on it, and their unit times go back to the timetable when the main sailing had its own time.
     * A date with no sailings is done and logs nothing. Logs `boat_sailing.reset`.
     */
    public function __invoke(User $actor, BoatDeparture $departure, CarbonImmutable $date): SailingResult
    {
        $property = Property::query()->findOrFail($departure->property_id);

        if ($date->toDateString() < $this->boatTripUsage->businessDate($property)->toDateString()) {
            return SailingResult::refused(SailingResult::REFUSAL_DATE_CLOSED);
        }

        return DB::transaction(function () use ($actor, $property, $departure, $date): SailingResult {
            $locked = BoatDeparture::query()->where('property_id', $property->id)->lockForUpdate()->findOrFail($departure->id);
            $rows = BoatSailing::query()
                ->where('property_id', $property->id)
                ->where('boat_departure_id', $locked->id)
                ->whereDate('date', $date->toDateString())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return SailingResult::done();
            }

            $views = collect($this->dateSailings->forDate($property, $date, [$locked->id], true)[$locked->id]);

            foreach ($views->skip(1) as $added) {
                $guests = (int) $this->dateSailings->tripsOn($added)
                    ->join('reservation_units', 'reservation_units.id', '=', 'unit_boat_trips.reservation_unit_id')
                    ->sum(DB::raw('reservation_units.adults + reservation_units.children'));

                if ($guests > 0) {
                    return SailingResult::refused(SailingResult::REFUSAL_SAILING_HAS_GUESTS, $guests);
                }
            }

            /** @var DateSailing $main */
            $main = $views->first();
            $unitsChanged = 0;

            if ($main->timeChanged) {
                $units = ReservationUnit::query()->whereIn('id', $this->dateSailings->tripsOn($main)->select('unit_boat_trips.reservation_unit_id'));
                $timetable = substr($locked->direction->propertyTime($locked), 0, 5);
                $unitsChanged = count(($this->setUnitTimes)($units, $locked->direction, $timetable));
            }

            BoatSailing::query()->whereKey($rows->modelKeys())->delete();

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('boat_sailing.reset')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_departure_id' => $locked->id,
                    'date' => $date->toDateString(),
                    'sailings_deleted' => $rows->count(),
                    'units_changed' => $unitsChanged,
                ])
                ->log('Boat departure reset to the timetable');

            return SailingResult::done();
        });
    }
}
