<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UploadPropertyPhotos
{
    /**
     * Add photos after the existing ones. Returns false, and stores nothing, when they would pass the limit.
     *
     * @param  list<UploadedFile>  $files
     */
    public function __invoke(User $actor, Property $property, array $files): bool
    {
        return DB::transaction(function () use ($actor, $property, $files): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($property->getMedia(Property::PHOTOS)->count() + count($files) > Property::PHOTO_LIMIT) {
                return false;
            }

            foreach ($files as $file) {
                $property->addMedia($file)
                    ->usingFileName(Str::uuid().'.'.$file->guessExtension())
                    ->toMediaCollection(Property::PHOTOS);
            }

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.updated')
                ->withProperties(['property_id' => $property->id, 'changed' => ['photos'], 'added' => count($files)])
                ->log('Property photos uploaded');

            return true;
        });
    }
}
