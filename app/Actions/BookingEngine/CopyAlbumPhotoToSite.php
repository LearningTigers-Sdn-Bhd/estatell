<?php

namespace App\Actions\BookingEngine;

use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\FileAdder;

/**
 * Copy a photo from the property album into one slot of the chosen template, replacing what was there. The slot keeps its
 * own file, so later album changes never touch the website. Returns false, and writes nothing, when the template has no
 * such slot or the property has no such photo.
 */
class CopyAlbumPhotoToSite
{
    public function __invoke(PropertyBookingSite $site, User $actor, string $slot, int $mediaId): bool
    {
        return DB::transaction(function () use ($site, $actor, $slot, $mediaId): bool {
            $site = PropertyBookingSite::query()->with(['property', 'siteTemplate.slots'])->lockForUpdate()->findOrFail($site->id);
            $photo = $site->property->getMedia(Property::PHOTOS)->firstWhere('id', $mediaId);

            if ($photo === null || $site->siteTemplate?->slots->firstWhere('key', $slot) === null) {
                return false;
            }

            $replaced = $site->getMedia(PropertyBookingSite::IMAGES, ['slot' => $slot]);

            $photo->copy(
                $site,
                PropertyBookingSite::IMAGES,
                fileName: Str::uuid().'.'.$photo->extension,
                fileAdderCallback: fn (FileAdder $adder): FileAdder => $adder->withCustomProperties(['slot' => $slot]),
            );

            $replaced->each->delete();

            activity()
                ->performedOn($site)
                ->causedBy($actor)
                ->event('booking-website.image-copied')
                ->withProperties(['property_id' => $site->property_id, 'slot' => $slot])
                ->log('Booking website image copied from the album');

            return true;
        });
    }
}
