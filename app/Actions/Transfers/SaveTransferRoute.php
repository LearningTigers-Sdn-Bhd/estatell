<?php

namespace App\Actions\Transfers;

use App\Models\Property;
use App\Models\TransferRoute;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveTransferRoute
{
    /**
     * Add a transfer route to the property, or change one (`$route`). It is never deleted, so the transfers that used it keep it;
     * turning it off hides it from the pickers. Logs `transfer_route.created` or `transfer_route.updated`.
     * See docs/35-transfers/01-land-transfers.md#settings.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $actor, Property $property, array $attributes, ?TransferRoute $route = null): TransferRoute
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $route): TransferRoute {
            $route ??= new TransferRoute;
            $route->fill($attributes);
            $route->property_id = $property->id;
            $event = $route->exists ? 'transfer_route.updated' : 'transfer_route.created';
            $route->save();

            activity()
                ->performedOn($route)
                ->causedBy($actor)
                ->event($event)
                ->withProperties(['property_id' => $property->id, 'name' => $route->name])
                ->log($event === 'transfer_route.created' ? 'Transfer route added' : 'Transfer route updated');

            return $route;
        });
    }
}
