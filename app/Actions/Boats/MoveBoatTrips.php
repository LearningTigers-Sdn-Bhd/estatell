<?php

namespace App\Actions\Boats;

use App\Data\Boats\BoatTripData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\SailingKind;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use App\Services\Boats\DateSailing;
use App\Services\Boats\DateSailings;
use App\Services\Reservations\ReservationRoomBox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MoveBoatTrips
{
    public function __construct(
        private SetBoatTrip $setBoatTrip,
        private DateSailings $dateSailings,
        private BoatTripUsage $boatTripUsage,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * Move trips to another sailing of the same date and direction, as one change. The target is a stored sailing
     * (`$sailingId`), or the main sailing of a departure (`$departureId`, for a date that has no rows yet).
     *
     * - Another boat of the same departure: `boat_sailing_id` is set (null for the main sailing). The booked departure and
     *   the fare stay.
     * - Another departure: `SetBoatTrip` runs with that departure, so the fare changes as for any departure change.
     * - The unit time follows the target sailing, and the tick clears, because the guests are now on another boat.
     *
     * Lock order is the departures (by id), then the units (by id), as `SetBoatTrips`. All or nothing: the first refusal
     * rolls everything back and names the room. Logs `reservation.boat_trip_moved` on each reservation. See
     * docs/24-boats/04-sailings.md#move-guests.
     *
     * @param  list<int>  $tripIds
     */
    public function __invoke(User $actor, Property $property, array $tripIds, ?int $sailingId = null, ?int $departureId = null): SailingResult
    {
        $ids = collect($tripIds)->map(intval(...))->unique()->sort()->values();
        $trips = UnitBoatTrip::query()->where('property_id', $property->id)->whereKey($ids->all())->with('reservationUnit')->orderBy('id')->get();

        if ($ids->isEmpty() || $trips->count() !== $ids->count()) {
            return SailingResult::refused(SailingResult::REFUSAL_SAILING_NOT_FOUND);
        }

        $targetSailing = null;

        if ($sailingId !== null) {
            $targetSailing = BoatSailing::query()
                ->where('property_id', $property->id)
                ->where('kind', SailingKind::Guests->value)
                ->whereNull('cancelled_at')
                ->find($sailingId);
            $departureId = $targetSailing?->boat_departure_id;
        }

        $toExtra = $targetSailing !== null && $targetSailing->boat_departure_id === null;
        $departure = $departureId === null ? null : BoatDeparture::query()->where('property_id', $property->id)->find($departureId);

        if ($departure === null && ! $toExtra) {
            return SailingResult::refused(SailingResult::REFUSAL_SAILING_NOT_FOUND);
        }

        $direction = $toExtra ? $targetSailing->direction : $departure->direction;

        $dates = $trips->map(fn (UnitBoatTrip $trip): string => $trip->reservationUnit->{$trip->direction->tripDateColumn()}->toDateString())->unique();
        $date = $targetSailing?->date ?? CarbonImmutable::parse((string) $dates->first());

        if ($dates->count() !== 1 || $dates->first() !== $date->toDateString()
            || $trips->contains(fn (UnitBoatTrip $trip): bool => $trip->direction !== $direction)
            || ($toExtra && $trips->contains(fn (UnitBoatTrip $trip): bool => $trip->boat_departure_id === null))) {
            return SailingResult::refused(SailingResult::REFUSAL_TARGET_MISMATCH);
        }

        if ($date->toDateString() < $this->boatTripUsage->businessDate($property)->toDateString()) {
            return SailingResult::refused(SailingResult::REFUSAL_DATE_CLOSED);
        }

        $failedUnit = null;

        try {
            return DB::transaction(function () use ($actor, $property, $trips, $departure, $direction, $date, $targetSailing, $toExtra, &$failedUnit): SailingResult {
                $departureIds = $trips->pluck('boat_departure_id')->push($departure?->id)->filter()->unique()->sort()->values()->all();
                BoatDeparture::query()->where('property_id', $property->id)->whereKey($departureIds)->orderBy('id')->lockForUpdate()->get();
                BoatSailing::query()->where('property_id', $property->id)->whereIn('boat_departure_id', $departureIds)->whereDate('date', $date->toDateString())->orderBy('id')->lockForUpdate()->get();
                $units = ReservationUnit::query()->with('reservation')->whereKey($trips->pluck('reservation_unit_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                if ($toExtra) {
                    BoatSailing::query()->where('property_id', $property->id)->whereKey($targetSailing->id)->lockForUpdate()->get();
                }

                $views = $this->dateSailings->forDate($property, $date, $departureIds, true);
                $extras = $this->dateSailings->extras($property, $date);
                $target = $toExtra
                    ? collect($extras)->first(fn (DateSailing $sailing): bool => $sailing->sailing?->id === $targetSailing->id)
                    : collect($views[$departure->id])->first(fn (DateSailing $sailing): bool => $targetSailing === null ? $sailing->isMain : $sailing->sailing?->id === $targetSailing->id);

                if ($target === null) {
                    return SailingResult::refused(SailingResult::REFUSAL_SAILING_NOT_FOUND);
                }

                $timeColumn = $direction === BoatDirection::In ? 'arrival_time' : 'departure_time';
                $postedFareKept = false;

                foreach ($trips as $listed) {
                    $trip = UnitBoatTrip::query()->lockForUpdate()->findOrFail($listed->id);
                    $unit = $units[$trip->reservation_unit_id];
                    $from = $this->dateSailings->sailingOf($trip, $views, $extras);

                    if (($toExtra || $trip->boat_departure_id === $departure->id) && $from?->key() === $target->key()) {
                        continue;
                    }

                    if ($toExtra) {
                        $trip->forceFill(['boat_sailing_id' => $target->sailing->id, 'ticked_at' => null, 'ticked_by' => null])->save();
                    } elseif ($trip->boat_departure_id === $departure->id) {
                        $trip->forceFill(['boat_sailing_id' => $target->isMain ? null : $target->sailing->id, 'ticked_at' => null, 'ticked_by' => null])->save();
                    } else {
                        $set = ($this->setBoatTrip)($actor, $unit, new BoatTripData(
                            direction: $trip->direction,
                            mode: BoatMode::Scheduled,
                            boatDepartureId: $departure->id,
                            note: $trip->note,
                        ));

                        if ($set->isRefused()) {
                            $failedUnit = $unit;

                            throw new BoatTripRefused((string) $set->refusal);
                        }

                        $postedFareKept = $postedFareKept || $set->postedFareKept;
                        $trip->refresh()->forceFill(['boat_sailing_id' => $target->isMain ? null : $target->sailing->id])->save();
                    }

                    if (substr((string) $unit->refresh()->{$timeColumn}, 0, 5) !== $target->propertyTime()) {
                        $unit->forceFill([$timeColumn => $target->propertyTime().':00'])->save();
                    }

                    activity()
                        ->performedOn($unit->reservation)
                        ->causedBy($actor)
                        ->event('reservation.boat_trip_moved')
                        ->withProperties([
                            'property_id' => $property->id,
                            'reservation_unit_id' => $unit->id,
                            'direction' => $trip->direction->value,
                            'from_boat_departure_id' => $listed->boat_departure_id,
                            'to_boat_departure_id' => $departure?->id,
                            'from_boat_sailing_id' => $from?->sailing?->id,
                            'to_boat_sailing_id' => $target->sailing?->id,
                            'boat' => $target->boat?->name,
                            'boat_time' => $target->leavesAt,
                            'unit_time' => $target->propertyTime(),
                        ])
                        ->log('Boat trip moved');
                }

                return SailingResult::done($target->sailing, $postedFareKept);
            });
        } catch (BoatTripRefused $refused) {
            return SailingResult::refused($refused->refusal, unitLabel: $failedUnit === null ? null : $this->label($failedUnit));
        }
    }

    /** "Double · 104", the label of the unit on the reservation page. Read only when a refusal names it. */
    private function label(ReservationUnit $unit): string
    {
        $unit->loadMissing('roomType');
        $assigned = $this->roomBox->assignmentsForUnits([$unit->id])[$unit->id] ?? null;

        return ReservationUnitOptionData::fromModel($unit, $assigned)->label;
    }
}
