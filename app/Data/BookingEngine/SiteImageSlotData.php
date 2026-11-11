<?php

namespace App\Data\BookingEngine;

use App\Models\SiteTemplateSlot;
use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One image row on the Design tab: the slot, and the image in it if there is one. The URLs are public.
 */
class SiteImageSlotData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public ?string $fileName,
        public ?string $url,
        public ?string $thumbUrl,
    ) {}

    public static function fromModels(SiteTemplateSlot $slot, ?Media $image): self
    {
        return new self(
            key: $slot->key,
            label: $slot->label,
            required: $slot->required,
            fileName: $image?->file_name,
            url: $image?->getUrl('web'),
            thumbUrl: $image?->getUrl('thumb'),
        );
    }
}
