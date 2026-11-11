<?php

namespace App\Data\Rooms;

use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One photo of a room type. The URLs are public.
 */
class RoomTypePhotoData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public string $thumbUrl,
    ) {}

    public static function fromModel(Media $media): self
    {
        return new self(
            id: $media->id,
            name: $media->file_name,
            url: $media->getUrl('web'),
            thumbUrl: $media->getUrl('thumb'),
        );
    }
}
