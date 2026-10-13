<?php

namespace App\Actions\Boats;

use App\Data\Boats\BoatTripData;
use App\Enums\BoatDirection;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use App\Services\Boats\BoatTripAccess;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class SetBoatTrip
{
    public function __construct(private BoatTripAccess $boatTripAccess) {}

    /**
     * Set one direction of a unit's boat trip and the unit's expected time that goes with it. A scheduled trip takes the
     * departure's property-side time (`arrives_at` for Boat in, `leaves_at` for Boat out). A charter or own boat trip
     * takes the time staff typed. The trip row is updated in place, so its id stays and its fare links stay with it.
     *
     * Lock order is the departure (shared), then the unit, the same as `UpdateBoatDeparture` (departure, then units), so
     * the two cannot deadlock. Every check runs before the first write. A set that changes nothing writes and logs nothing.
     * Seats never refuse. The route checks `reservations.manage` and the `boats` middleware; this checks the feature for
     * other callers (bookings, Add unit). See docs/24-boats/02-boat-trips.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, BoatTripData $data): BoatTripResult
    {
        $property = Property::query()->findOrFail($unit->property_id);

        if (! Feature::for($property)->active(Boats::class)) {
            return BoatTripResult::refused(BoatTripResult::REFUSAL_BOATS_OFF);
        }

        return DB::transaction(function () use ($actor, $unit, $data): BoatTripResult {
            $departure = $data->mode->needsDeparture()
                ? BoatDeparture::query()
                    ->whereKey($data->boatDepartureId)
                    ->where('property_id', $unit->property_id)
                    ->where('direction', $data->direction->value)
                    ->where('active', true)
                    ->sharedLock()
                    ->first()
                : null;

            $locked = ReservationUnit::query()->with('reservation')->lockForUpdate()->findOrFail($unit->id);

            $refusal = $this->boatTripAccess->refusalFor($locked, $data->direction);

            if ($refusal !== null) {
                return BoatTripResult::refused($refusal);
            }

            if ($data->mode->needsDeparture() && $departure === null) {
                return BoatTripResult::refused(BoatTripResult::REFUSAL_DEPARTURE_NOT_FOUND);
            }

            if (! $data->mode->needsDeparture() && blank($data->time)) {
                return BoatTripResult::refused(BoatTripResult::REFUSAL_TIME_REQUIRED);
            }

            $time = $data->mode->needsDeparture()
                ? $data->direction->propertyTime($departure)
                : substr((string) $data->time, 0, 5).':00';
            $boatDepartureId = $data->mode->needsDeparture() ? $departure->id : null;
            $note = filled($data->note) ? trim((string) $data->note) : null;
            $timeColumn = $data->direction === BoatDirection::In ? 'arrival_time' : 'departure_time';

            $existing = UnitBoatTrip::query()
                ->where('reservation_unit_id', $locked->id)
                ->where('direction', $data->direction->value)
                ->first();

            $unchanged = $existing !== null
                && $existing->mode === $data->mode
                && $existing->boat_departure_id === $boatDepartureId
                && $existing->note === $note
                && substr($locked->{$timeColumn}, 0, 5) === substr($time, 0, 5);

            if ($unchanged) {
                return BoatTripResult::done();
            }

            $trip = UnitBoatTrip::query()->updateOrCreate(
                ['reservation_unit_id' => $locked->id, 'direction' => $data->direction->value],
                ['property_id' => $locked->property_id, 'mode' => $data->mode->value, 'boat_departure_id' => $boatDepartureId, 'note' => $note],
            );

            $locked->update([$timeColumn => $time]);

            $this->syncFare($locked, $trip);

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.boat_trip_set')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'direction' => $data->direction->value,
                    'mode' => $data->mode->value,
                    'boat_departure_id' => $boatDepartureId,
                    'boat_time' => substr($departure?->leaves_at ?? $time, 0, 5),
                    'pickup_point' => $departure?->pickup_point,
                    'unit_time' => substr($time, 0, 5),
                    'note' => $note,
                ])
                ->log('Boat trip set');

            return BoatTripResult::done();
        });
    }

    /**
     * Fare rows for the trip go here. The boat fare item fills this in; until then a trip adds nothing.
     */
    private function syncFare(ReservationUnit $unit, UnitBoatTrip $trip): void {}
}
