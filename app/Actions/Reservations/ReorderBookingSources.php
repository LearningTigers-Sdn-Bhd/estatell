<?php

namespace App\Actions\Reservations;

use App\Models\BookingSource;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderBookingSources
{
    /**
     * Set the display order of the property's active booking sources that are not system rows.
     *
     * Returns false unless the ids are exactly those sources.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $orderable = BookingSource::query()
                ->where('property_id', $property->id)
                ->where('is_system', false)
                ->active()
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if (count($orderedIds) !== count($orderable) || array_diff($orderedIds, $orderable) !== [] || array_diff($orderable, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                BookingSource::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }

            activity()
                ->causedBy($actor)
                ->event('booking_source.reordered')
                ->withProperties(['property_id' => $property->id, 'order' => $orderedIds])
                ->log('Booking sources reordered');

            return true;
        });
    }
}
