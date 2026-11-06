<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ReorderPropertyPhotos
{
    /**
     * Set the photo order; the first photo is the cover.
     *
     * Returns false unless the ids are exactly the property's photos.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(User $actor, Property $property, array $orderedIds): bool
    {
        return DB::transaction(function () use ($actor, $property, $orderedIds): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $current = $property->getMedia(Property::PHOTOS)->pluck('id')->all();

            if (count($orderedIds) !== count($current) || array_diff($orderedIds, $current) !== []) {
                return false;
            }

            Media::setNewOrder($orderedIds);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.updated')
                ->withProperties(['property_id' => $property->id, 'changed' => ['photo_order']])
                ->log('Property photos reordered');

            return true;
        });
    }
}
