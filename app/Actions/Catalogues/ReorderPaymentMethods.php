<?php

namespace App\Actions\Catalogues;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderPaymentMethods
{
    /**
     * Set the display order of the property's active payment methods.
     *
     * Returns false unless the ids are exactly the active payment methods.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $active = $property->paymentMethods()->active()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($active) || array_diff($orderedIds, $active) !== [] || array_diff($active, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                $property->paymentMethods()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($actor)
                ->event('payment_method.reordered')
                ->withProperties(['property_id' => $property->id, 'order' => $orderedIds])
                ->log('Payment methods reordered');

            return true;
        });
    }
}
