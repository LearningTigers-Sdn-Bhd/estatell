<?php

namespace App\Actions\Boats;

use App\Actions\Reservations\AddUnitAddOn;
use App\Data\Boats\BoatTripData;
use App\Data\Reservations\NewAddOnData;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\BoatTripAddOn;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use App\Services\Boats\BoatTripAccess;
use App\Services\Boats\DateSailing;
use App\Services\Boats\DateSailings;
use App\Services\Boats\SailingTimes;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class SetBoatTrip
{
    public function __construct(
        private BoatTripAccess $boatTripAccess,
        private SyncBoatFare $syncBoatFare,
        private AddUnitAddOn $addUnitAddOn,
        private SailingTimes $sailingTimes,
        private DateSailings $dateSailings,
    ) {}

    /**
     * Set one direction of a unit's boat trip and the unit's expected time that goes with it. A scheduled trip takes the
     * departure's property-side time (`arrives_at` for Boat in, `leaves_at` for Boat out). A charter or own boat trip
     * takes the time staff typed. The trip row is updated in place, so its id stays and its fare links stay with it.
     *
     * Lock order is the departure (shared), then the unit, the same as `UpdateBoatDeparture` (departure, then units), so
     * the two cannot deadlock. Every check runs before the first write. A set that changes nothing writes and logs nothing.
     * The fare is synced inside the same transaction (`SyncBoatFare`). A fare refusal throws `BoatTripRefused` inside, so
     * the trip, the time and the fare rows all roll back, and the Action returns the refusal. Seats never refuse. The route
     * checks `reservations.manage` and the `boats` middleware; this checks the feature for other callers (bookings, Add
     * unit). See docs/24-boats/02-boat-trips.md.
     */
    public function __invoke(?User $actor, ReservationUnit $unit, BoatTripData $data): BoatTripResult
    {
        $property = Property::query()->findOrFail($unit->property_id);

        if (! Feature::for($property)->active(Boats::class)) {
            return BoatTripResult::refused(BoatTripResult::REFUSAL_BOATS_OFF);
        }

        try {
            return DB::transaction(fn (): BoatTripResult => $this->save($actor, $property, $unit, $data));
        } catch (BoatTripRefused $refused) {
            return BoatTripResult::refused($refused->refusal);
        }
    }

    private function save(?User $actor, Property $property, ReservationUnit $unit, BoatTripData $data): BoatTripResult
    {
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

        $tripDate = $locked->{$data->direction->tripDateColumn()};

        if ($departure !== null && $this->dateSailings->cancelledDepartureIds([$departure->id], $tripDate) !== []) {
            return BoatTripResult::refused(
                BoatTripResult::REFUSAL_DEPARTURE_CANCELLED,
                __('The :time boat :direction does not run on :date.', [
                    'time' => substr($departure->leaves_at, 0, 5),
                    'direction' => $data->direction->value,
                    'date' => $tripDate->format('j M'),
                ]),
            );
        }

        if (! $data->mode->needsDeparture() && blank($data->time)) {
            return BoatTripResult::refused(BoatTripResult::REFUSAL_TIME_REQUIRED);
        }

        $sailing = null;

        if ($departure !== null && $data->pickedSailing) {
            $sailing = collect($this->dateSailings->forDate($property, $tripDate, [$departure->id])[$departure->id] ?? [])
                ->first(fn (DateSailing $candidate): bool => $data->boatSailingId === null ? $candidate->isMain : $candidate->sailing?->id === $data->boatSailingId);

            if ($sailing === null) {
                return BoatTripResult::refused(BoatTripResult::REFUSAL_DEPARTURE_NOT_FOUND);
            }
        }

        $time = match (true) {
            $sailing !== null => $sailing->propertyTime().':00',
            $data->mode->needsDeparture() => $this->sailingTimes->forTrip($departure, $tripDate),
            default => substr((string) $data->time, 0, 5).':00',
        };
        $boatDepartureId = $data->mode->needsDeparture() ? $departure->id : null;
        $boatSailingId = $sailing === null || $sailing->isMain ? null : $sailing->sailing?->id;
        $timeColumn = $data->direction === BoatDirection::In ? 'arrival_time' : 'departure_time';

        $existing = UnitBoatTrip::query()
            ->where('reservation_unit_id', $locked->id)
            ->where('direction', $data->direction->value)
            ->first();

        $note = match (true) {
            filled($data->note) => trim((string) $data->note),
            $data->mode->needsDeparture() => $existing?->note,
            default => null,
        };

        $unchanged = $existing !== null
            && $existing->mode === $data->mode
            && $existing->boat_departure_id === $boatDepartureId
            && (! $data->pickedSailing || $existing->boat_sailing_id === $boatSailingId)
            && $existing->note === $note
            && substr($locked->{$timeColumn}, 0, 5) === substr($time, 0, 5);

        if ($unchanged) {
            return BoatTripResult::done();
        }

        $otherBoat = $existing !== null && (
            $existing->mode !== $data->mode
            || $existing->boat_departure_id !== $boatDepartureId
            || ($data->pickedSailing && $existing->boat_sailing_id !== $boatSailingId)
        );

        $trip = UnitBoatTrip::query()->updateOrCreate(
            ['reservation_unit_id' => $locked->id, 'direction' => $data->direction->value],
            [
                'property_id' => $locked->property_id,
                'mode' => $data->mode->value,
                'boat_departure_id' => $boatDepartureId,
                'note' => $note,
                ...($otherBoat ? ['ticked_at' => null, 'ticked_by' => null, 'boat_sailing_id' => null] : []),
                ...($data->pickedSailing ? ['boat_sailing_id' => $boatSailingId] : []),
            ],
        );

        $locked->update([$timeColumn => $time]);

        $fare = ($this->syncBoatFare)($actor, $locked, $trip, $departure);

        if ($fare->isRefused()) {
            throw new BoatTripRefused((string) $fare->refusal);
        }

        if ($data->mode === BoatMode::Charter && ! $fare->postedFareKept) {
            $this->addCharterCharge($actor, $locked, $trip);
        }

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
                'boat_time' => $sailing?->leavesAt ?? substr($departure->leaves_at ?? $time, 0, 5),
                'pickup_point' => $departure?->pickup_point,
                'unit_time' => substr($time, 0, 5),
                'note' => $note,
            ])
            ->log('Boat trip set');

        return BoatTripResult::done($fare->postedFareKept);
    }

    /**
     * A Charter trip adds the property's charter charge item once (`charter_charge_item_id`), linked to the trip like a
     * fare, so changing or clearing the trip removes it while it is not charged. Nothing is added when no item is set or
     * the item is off.
     */
    private function addCharterCharge(User $actor, ReservationUnit $unit, UnitBoatTrip $trip): void
    {
        $itemId = $unit->property->settings?->charter_charge_item_id;

        if ($itemId === null) {
            return;
        }

        $added = ($this->addUnitAddOn)($actor, $unit, new NewAddOnData(chargeItemId: $itemId));

        if ($added->isRefused()) {
            return;
        }

        foreach ($added->addOns as $addOn) {
            BoatTripAddOn::query()->create(['unit_boat_trip_id' => $trip->id, 'unit_add_on_id' => $addOn->id]);
        }
    }
}
