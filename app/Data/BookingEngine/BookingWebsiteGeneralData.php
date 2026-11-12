<?php

namespace App\Data\BookingEngine;

use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Services\BookingEngine\BookingSiteChecklist;
use Spatie\LaravelData\Data;

/**
 * What the General tab shows. `slugLocked` is true once the site is published, because a new slug breaks links already shared.
 */
class BookingWebsiteGeneralData extends Data
{
    /**
     * @param  list<BookingSiteChecklistItemData>  $checklist
     */
    public function __construct(
        public string $mode,
        public bool $published,
        public ?string $publishedAt,
        public string $slug,
        public bool $slugLocked,
        public string $baseUrl,
        public string $webAddress,
        public ?string $googleMapsUrl,
        public ?string $aboutText,
        public array $checklist,
        public bool $canPublish,
    ) {}

    public static function fromModels(Property $property, PropertyBookingSite $site, BookingSiteChecklist $checklist): self
    {
        $baseUrl = rtrim((string) config('estatell.booking_site_url'), '/');
        $slug = (string) $property->slug;

        return new self(
            mode: $site->mode->value,
            published: $site->isPublished(),
            publishedAt: $site->published_at?->toIso8601String(),
            slug: $slug,
            slugLocked: $site->isPublished(),
            baseUrl: $baseUrl,
            webAddress: self::webAddressFor($property),
            googleMapsUrl: $property->google_maps_url,
            aboutText: $site->about_text,
            checklist: $checklist->items($site),
            canPublish: $checklist->isComplete($site),
        );
    }

    /**
     * The microsite address of a property: the booking site base URL and the property's slug.
     */
    public static function webAddressFor(Property $property): string
    {
        return rtrim((string) config('estatell.booking_site_url'), '/').'/'.$property->slug;
    }
}
