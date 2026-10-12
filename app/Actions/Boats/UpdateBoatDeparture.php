<?php

namespace App\Actions\Boats;

use App\Models\BoatDeparture;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateBoatDeparture
{
    /**
     * Save a departure. A save that changes nothing is not logged. Nothing uses a departure yet, so only the row
     * changes; moving unit times comes with boat trips.
     *
     * @param  array<string, mixed>  $attributes  Validated departure fields, the full row.
     */
    public function __invoke(User $actor, BoatDeparture $boatDeparture, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $boatDeparture, $attributes): bool {
            $boatDeparture = BoatDeparture::query()->lockForUpdate()->findOrFail($boatDeparture->id);
            $before = $this->snapshot($boatDeparture);

            $boatDeparture->fill($attributes);
            $after = $this->snapshot($boatDeparture);

            if ($after === $before) {
                return true;
            }

            $boatDeparture->save();

            activity()
                ->performedOn($boatDeparture)
                ->causedBy($actor)
                ->event('boat_departure.updated')
                ->withProperties(['property_id' => $boatDeparture->property_id, 'before' => $before, 'after' => $after])
                ->log('Boat departure updated');

            return true;
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
