<?php

namespace App\Services\Boats;

use App\Enums\BoatDirection;
use App\Enums\StayType;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Illuminate\Support\Collection;

/**
 * The boat in and boat out text of a reservation for the reservations list card. It reads the units the card counts
 * (the ones that hold inventory, or all of them when none does), leaves day use units out, and shows one label when
 * every unit agrees, "Mixed" when they differ and "—" when none has a trip. A reservation of day use units only has no
 * summary. Load `units.boatTrips` first. Reads only.
 */
class ReservationBoatSummary
{
    public function __construct(private BoatTimeLabel $labels) {}

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return array<int, array{in: string, out: string}> By reservation id. A reservation with no summary is left out.
     */
    public function forReservations(Collection $reservations): array
    {
        $summaries = [];

        foreach ($reservations as $reservation) {
            $holding = $reservation->units->filter(fn (ReservationUnit $unit): bool => $unit->status->holdsInventory());
            $units = ($holding->isEmpty() ? $reservation->units : $holding)
                ->reject(fn (ReservationUnit $unit): bool => $unit->stay_type === StayType::DayUse);

            if ($units->isEmpty()) {
                continue;
            }

            $summaries[$reservation->id] = [
                'in' => $this->summarise($units, BoatDirection::In),
                'out' => $this->summarise($units, BoatDirection::Out),
            ];
        }

        return $summaries;
    }

    /**
     * @param  Collection<int, ReservationUnit>  $units
     */
    private function summarise(Collection $units, BoatDirection $direction): string
    {
        $labels = $units->map(fn (ReservationUnit $unit): string => $this->labels->forUnit($unit, $direction))->unique();

        return $labels->count() === 1 ? $labels->first() : __('Mixed');
    }
}
