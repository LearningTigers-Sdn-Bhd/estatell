<?php

namespace App\Actions\BookingEngine;

use App\Models\PropertyBookingSite;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Put an image in one slot of the chosen template, replacing what was there. Returns false, and writes nothing, when the
 * site has no template or the template has no slot with that key.
 */
class UploadSiteImage
{
    public function __invoke(PropertyBookingSite $site, User $actor, string $slot, UploadedFile $file): bool
    {
        return DB::transaction(function () use ($site, $actor, $slot, $file): bool {
            $site = PropertyBookingSite::query()->with('siteTemplate.slots')->lockForUpdate()->findOrFail($site->id);

            if ($site->siteTemplate?->slots->firstWhere('key', $slot) === null) {
                return false;
            }

            $site->getMedia(PropertyBookingSite::IMAGES, ['slot' => $slot])->each->delete();

            $site->addMedia($file)
                ->usingFileName(Str::uuid().'.'.$file->guessExtension())
                ->withCustomProperties(['slot' => $slot])
                ->toMediaCollection(PropertyBookingSite::IMAGES);

            activity()
                ->performedOn($site)
                ->causedBy($actor)
                ->event('booking-website.image-uploaded')
                ->withProperties(['property_id' => $site->property_id, 'slot' => $slot])
                ->log('Booking website image uploaded');

            return true;
        });
    }
}
