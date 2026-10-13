<?php

namespace App\Actions\Boats;

use App\Data\Boats\BoatTripData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\BoatDirection;
use App\Models\BoatDeparture;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Support\Facades\DB;
use Throwable;

class SetBoatTrips
{
    public function __construct(
        private SetBoatTrip $setBoatTrip,
        private ClearBoatTrip $clearBoatTrip,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * Set or clear the boat trips of several units of one reservation, as one change. `$changes` is keyed by unit id, and
     * each direction is in it only when the staff may change it: a `BoatTripData` sets it, null clears it ("Not set"), and
     * a missing key leaves it as it is.
     *
     * Lock order: every distinct departure first (shared, by id), then the units (by id). `UpdateBoatDeparture` locks a
     * departure before its units, so a batch that locked a unit before a later departure could deadlock with it. Each
     * unit then runs `SetBoatTrip` or `ClearBoatTrip` in the same transaction, in Boat in, Boat out order. The first
     * refusal rolls back everything, so no unit is saved and nothing is logged. See docs/24-boats/02-boat-trips.md.
     *
     * @param  array<int, array{in?: BoatTripData|null, out?: BoatTripData|null}>  $changes
     */
    public function __invoke(User $actor, Reservation $reservation, array $changes): BoatTripsResult
    {
        $unitIds = array_keys($changes);
        sort($unitIds);
        $departureIds = collect($changes)
            ->flatMap(fn (array $change): array => [$change['in'] ?? null, $change['out'] ?? null])
            ->filter(fn (?BoatTripData $trip): bool => $trip !== null && $trip->boatDepartureId !== null)
            ->map(fn (BoatTripData $trip): int => (int) $trip->boatDepartureId)
            ->unique()
            ->sort()
            ->values()
            ->all();

        DB::beginTransaction();
        $postedFareKept = false;

        try {
            if ($departureIds !== []) {
                BoatDeparture::query()->whereKey($departureIds)->orderBy('id')->sharedLock()->get();
            }

            $units = ReservationUnit::query()
                ->where('reservation_id', $reservation->id)
                ->whereKey($unitIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($units as $unit) {
                foreach (BoatDirection::cases() as $direction) {
                    $change = $changes[$unit->id];

                    if (! array_key_exists($direction->value, $change)) {
                        continue;
                    }

                    $trip = $change[$direction->value];
                    $result = $trip === null
                        ? $this->clearBoatTrip->__invoke($actor, $unit, $direction)
                        : $this->setBoatTrip->__invoke($actor, $unit, $trip);

                    if ($result->isRefused()) {
                        DB::rollBack();

                        return BoatTripsResult::refused((string) $result->refusal, $unit->id, $this->label($unit));
                    }

                    $postedFareKept = $postedFareKept || $result->postedFareKept;
                }
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return BoatTripsResult::done($postedFareKept);
    }

    /** "Double · 104", the label of the unit on the reservation page. Read only when a refusal names it. */
    private function label(ReservationUnit $unit): string
    {
        $unit->loadMissing('roomType');
        $assigned = $this->roomBox->assignmentsForUnits([$unit->id])[$unit->id] ?? null;

        return ReservationUnitOptionData::fromModel($unit, $assigned)->label;
    }
}
