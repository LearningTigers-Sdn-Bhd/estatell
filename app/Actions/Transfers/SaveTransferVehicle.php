<?php

namespace App\Actions\Transfers;

use App\Models\Property;
use App\Models\TransferVehicle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveTransferVehicle
{
    /**
     * Add a vehicle to the property, or change one (`$vehicle`). It is never deleted, so the transfers that used it keep it;
     * turning it off hides it from the pickers. Logs `transfer_vehicle.created` or `transfer_vehicle.updated`.
     * See docs/35-transfers/01-land-transfers.md#settings.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $actor, Property $property, array $attributes, ?TransferVehicle $vehicle = null): TransferVehicle
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $vehicle): TransferVehicle {
            $vehicle ??= new TransferVehicle;
            $vehicle->fill($attributes);
            $vehicle->property_id = $property->id;
            $event = $vehicle->exists ? 'transfer_vehicle.updated' : 'transfer_vehicle.created';
            $vehicle->save();

            activity()
                ->performedOn($vehicle)
                ->causedBy($actor)
                ->event($event)
                ->withProperties(['property_id' => $property->id, 'name' => $vehicle->name])
                ->log($event === 'transfer_vehicle.created' ? 'Vehicle added' : 'Vehicle updated');

            return $vehicle;
        });
    }
}
