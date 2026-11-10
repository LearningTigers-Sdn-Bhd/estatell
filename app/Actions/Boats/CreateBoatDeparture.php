<?php

namespace App\Actions\Boats;

use App\Models\BoatDeparture;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateBoatDeparture
{
    public function __construct(private SyncBoatDepartureCrew $syncCrew) {}

    /**
     * Add a departure to the property, available. The property row is locked so two saves of the same time run one
     * after the other; the unique index still guards the time.
     *
     * @param  array<string, mixed>  $attributes  Validated departure fields, with `in_charge_id` and `assistant_ids` for the default crew.
     */
    public function __invoke(Property $property, User $actor, array $attributes): BoatDeparture
    {
        return DB::transaction(function () use ($property, $actor, $attributes): BoatDeparture {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            $inChargeId = Arr::pull($attributes, 'in_charge_id');
            $assistantIds = Arr::pull($attributes, 'assistant_ids') ?? [];

            $boatDeparture = $property->boatDepartures()->create([
                'active' => true,
                ...$attributes,
            ]);

            ($this->syncCrew)($boatDeparture, $inChargeId === null ? null : (int) $inChargeId, array_map('intval', $assistantIds));

            activity()
                ->performedOn($boatDeparture)
                ->causedBy($actor)
                ->event('boat_departure.created')
                ->withProperties(['property_id' => $property->id, 'after' => $this->snapshot($boatDeparture) + ['crew' => $this->crewSnapshot($boatDeparture)]])
                ->log('Boat departure created');

            return $boatDeparture;
        });
    }

    /** @return array{direction: string, leaves_at: string, arrives_at: string, pickup_point: string|null, seats: int|null, charge_item_id: int|null, default_boat_id: int|null, active: bool} */
    private function snapshot(BoatDeparture $boatDeparture): array
    {
        return [
            'direction' => $boatDeparture->direction->value,
            'leaves_at' => substr($boatDeparture->leaves_at, 0, 5),
            'arrives_at' => substr($boatDeparture->arrives_at, 0, 5),
            'pickup_point' => $boatDeparture->pickup_point,
            'seats' => $boatDeparture->seats,
            'charge_item_id' => $boatDeparture->charge_item_id,
            'default_boat_id' => $boatDeparture->default_boat_id,
            'active' => $boatDeparture->active,
        ];
    }

    /** @return array{in_charge: int|null, assistants: list<int>} */
    private function crewSnapshot(BoatDeparture $boatDeparture): array
    {
        $crew = $boatDeparture->crew()->get();

        return [
            'in_charge' => $crew->first(fn ($member) => (bool) $member->pivot->in_charge)?->id,
            'assistants' => $crew->reject(fn ($member) => (bool) $member->pivot->in_charge)->pluck('id')->sort()->values()->all(),
        ];
    }
}
