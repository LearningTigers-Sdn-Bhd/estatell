<?php

namespace App\Actions\Transfers;

use App\Models\Property;
use App\Models\TransferDriver;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveTransferDriver
{
    /**
     * Add a driver to the property, or change one (`$driver`). It is never deleted, so the transfers that used it keep it;
     * turning it off hides it from the pickers. Logs `transfer_driver.created` or `transfer_driver.updated`.
     * See docs/35-transfers/01-land-transfers.md#settings.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $actor, Property $property, array $attributes, ?TransferDriver $driver = null): TransferDriver
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $driver): TransferDriver {
            $driver ??= new TransferDriver;
            $driver->fill($attributes);
            $driver->property_id = $property->id;
            $event = $driver->exists ? 'transfer_driver.updated' : 'transfer_driver.created';
            $driver->save();

            activity()
                ->performedOn($driver)
                ->causedBy($actor)
                ->event($event)
                ->withProperties(['property_id' => $property->id, 'name' => $driver->name])
                ->log($event === 'transfer_driver.created' ? 'Driver added' : 'Driver updated');

            return $driver;
        });
    }
}
