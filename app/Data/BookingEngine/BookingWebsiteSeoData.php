<?php

namespace App\Data\BookingEngine;

use App\Models\Property;
use App\Models\PropertyBookingSite;
use Spatie\LaravelData\Data;

/**
 * What the SEO tab shows: the saved texts and what the Google preview needs.
 */
class BookingWebsiteSeoData extends Data
{
    public function __construct(
        public ?string $seoTitle,
        public ?string $seoDescription,
        public string $propertyName,
        public string $webAddress,
    ) {}

    public static function fromModels(Property $property, PropertyBookingSite $site): self
    {
        return new self(
            seoTitle: $site->seo_title,
            seoDescription: $site->seo_description,
            propertyName: $property->name,
            webAddress: rtrim((string) config('estatell.booking_site_url'), '/').'/'.$property->slug,
        );
    }
}
