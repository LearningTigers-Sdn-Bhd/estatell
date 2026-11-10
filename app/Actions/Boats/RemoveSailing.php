<?php

namespace App\Actions\Boats;

use App\Enums\SailingKind;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use App\Services\Boats\DateSailing;
use App\Services\Boats\DateSailings;
use Illuminate\Support\Facades\DB;

class RemoveSailing
{
    public function __construct(private DateSailings $dateSailings, private BoatTripUsage $boatTripUsage) {}

    /**
     * Delete an added boat of a departure, or an extra trip with no guests. The main sailing is not removed here (`sailing_is_main`; Default resets the
     * date), a sailing with guests is refused (`sailing_has_guests`), and a date before the business date is read only
     * (`date_closed`). Locks the departure, then the date's sailings. Logs `boat_sailing.removed`.
     */
    public function __invoke(User $actor, BoatSailing $sailing): SailingResult
    {
        $property = Property::query()->findOrFail($sailing->property_id);

        if ($sailing->date->toDateString() < $this->boatTripUsage->businessDate($property)->toDateString()) {
            return SailingResult::refused(SailingResult::REFUSAL_DATE_CLOSED);
        }

        if ($sailing->boat_departure_id === null) {
            return $this->removeExtra($actor, $property, $sailing);
        }

        return DB::transaction(function () use ($actor, $property, $sailing): SailingResult {
            $departure = BoatDeparture::query()->where('property_id', $property->id)->lockForUpdate()->findOrFail($sailing->boat_departure_id);
            $rows = BoatSailing::query()
                ->where('boat_departure_id', $departure->id)
                ->whereDate('date', $sailing->date->toDateString())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (! $rows->contains('id', $sailing->id)) {
                return SailingResult::refused(SailingResult::REFUSAL_SAILING_NOT_FOUND);
            }

            if ($rows->first()->id === $sailing->id) {
                return SailingResult::refused(SailingResult::REFUSAL_SAILING_IS_MAIN);
            }

            $view = collect($this->dateSailings->forDate($property, $sailing->date, [$departure->id], true)[$departure->id])
                ->first(fn (DateSailing $date): bool => $date->sailing?->id === $sailing->id);
            $guests = (int) $this->dateSailings->tripsOn($view)
                ->join('reservation_units', 'reservation_units.id', '=', 'unit_boat_trips.reservation_unit_id')
                ->sum(DB::raw('reservation_units.adults + reservation_units.children'));

            if ($guests > 0) {
                return SailingResult::refused(SailingResult::REFUSAL_SAILING_HAS_GUESTS, $guests);
            }

            $sailing->delete();

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('boat_sailing.removed')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_sailing_id' => $sailing->id,
                    'boat_departure_id' => $departure->id,
                    'date' => $sailing->date->toDateString(),
                    'boat_id' => $sailing->boat_id,
                ])
                ->log('Boat removed from a departure');

            return SailingResult::done();
        });
    }

    /** An extra trip is deleted while it has no guests. It has no main sailing to keep. */
    private function removeExtra(User $actor, Property $property, BoatSailing $sailing): SailingResult
    {
        return DB::transaction(function () use ($actor, $property, $sailing): SailingResult {
            $locked = BoatSailing::query()
                ->where('property_id', $property->id)
                ->whereNull('boat_departure_id')
                ->where('kind', SailingKind::Guests->value)
                ->lockForUpdate()
                ->find($sailing->id);

            if ($locked === null) {
                return SailingResult::refused(SailingResult::REFUSAL_SAILING_NOT_FOUND);
            }

            $view = collect($this->dateSailings->extras($property, $locked->date))->first(fn (DateSailing $extra): bool => $extra->sailing?->id === $locked->id);
            $guests = (int) $this->dateSailings->tripsOn($view)
                ->join('reservation_units', 'reservation_units.id', '=', 'unit_boat_trips.reservation_unit_id')
                ->sum(DB::raw('reservation_units.adults + reservation_units.children'));

            if ($guests > 0) {
                return SailingResult::refused(SailingResult::REFUSAL_SAILING_HAS_GUESTS, $guests);
            }

            $locked->delete();

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('boat_sailing.removed')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_sailing_id' => $locked->id,
                    'boat_departure_id' => null,
                    'date' => $locked->date->toDateString(),
                    'boat_id' => $locked->boat_id,
                ])
                ->log('Extra boat trip removed');

            return SailingResult::done();
        });
    }
}
