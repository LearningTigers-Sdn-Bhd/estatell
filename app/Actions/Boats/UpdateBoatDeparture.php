<?php

namespace App\Actions\Boats;

use App\Enums\BoatDirection;
use App\Models\BoatDeparture;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use Illuminate\Support\Facades\DB;

class UpdateBoatDeparture
{
    public function __construct(private BoatTripUsage $boatTripUsage) {}

    /**
     * Save a departure. The departure row is locked first, then the upcoming units of its trips, in id order (the same
     * order `SetBoatTrip` uses). When the property-side time changes (`arrives_at` for Boat in, `leaves_at` for Boat out),
     * every upcoming unit of a scheduled trip takes it as its `arrival_time` or `departure_time`, in the same transaction.
     * A direction change is refused while any trip uses the departure. A save that changes nothing is not logged. The
     * log counts the bookings whose times moved. See docs/24-boats/01-boat-settings.md#actions.
     *
     * @param  array<string, mixed>  $attributes  Validated departure fields, the full row.
     */
    public function __invoke(User $actor, BoatDeparture $boatDeparture, array $attributes): BoatDepartureResult
    {
        return DB::transaction(function () use ($actor, $boatDeparture, $attributes): BoatDepartureResult {
            $boatDeparture = BoatDeparture::query()->lockForUpdate()->findOrFail($boatDeparture->id);
            $before = $this->snapshot($boatDeparture);
            $oldPropertyTime = substr($boatDeparture->direction->propertyTime($boatDeparture), 0, 5);

            $direction = BoatDirection::tryFrom((string) ($attributes['direction'] ?? $boatDeparture->direction->value));

            if ($direction !== $boatDeparture->direction && $this->boatTripUsage->isUsed($boatDeparture)) {
                return BoatDepartureResult::refused(BoatDepartureResult::REFUSAL_DEPARTURE_IN_USE);
            }

            $boatDeparture->fill($attributes);
            $after = $this->snapshot($boatDeparture);

            if ($after === $before) {
                return BoatDepartureResult::done();
            }

            $boatDeparture->save();

            $bookingsChanged = 0;
            $unitsChanged = 0;
            $newPropertyTime = substr($boatDeparture->direction->propertyTime($boatDeparture), 0, 5);

            if ($newPropertyTime !== $oldPropertyTime) {
                $units = $this->boatTripUsage->upcomingUnits($boatDeparture, $this->boatTripUsage->businessDate($boatDeparture->property), lock: true);

                if ($units->isNotEmpty()) {
                    $column = $boatDeparture->direction === BoatDirection::In ? 'arrival_time' : 'departure_time';

                    ReservationUnit::query()->whereKey($units->modelKeys())->update([$column => $newPropertyTime.':00', 'updated_at' => now()]);

                    $unitsChanged = $units->count();
                    $bookingsChanged = $units->pluck('reservation_id')->unique()->count();
                }
            }

            activity()
                ->performedOn($boatDeparture)
                ->causedBy($actor)
                ->event('boat_departure.updated')
                ->withProperties([
                    'property_id' => $boatDeparture->property_id,
                    'before' => $before,
                    'after' => $after,
                    'units_changed' => $unitsChanged,
                    'bookings_changed' => $bookingsChanged,
                ])
                ->log('Boat departure updated');

            return BoatDepartureResult::done($bookingsChanged);
        });
    }

    /** @return array{direction: string, leaves_at: string, arrives_at: string, pickup_point: string|null, seats: int|null, charge_item_id: int|null, active: bool} */
    private function snapshot(BoatDeparture $boatDeparture): array
    {
        return [
            'direction' => $boatDeparture->direction->value,
            'leaves_at' => substr($boatDeparture->leaves_at, 0, 5),
            'arrives_at' => substr($boatDeparture->arrives_at, 0, 5),
            'pickup_point' => $boatDeparture->pickup_point,
            'seats' => $boatDeparture->seats,
            'charge_item_id' => $boatDeparture->charge_item_id,
            'active' => $boatDeparture->active,
        ];
    }
}
