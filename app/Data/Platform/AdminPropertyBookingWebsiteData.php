<?php

namespace App\Data\Platform;

use App\Data\BookingEngine\BookingWebsiteGeneralData;
use App\Enums\BookingEngineMode;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use Spatie\LaravelData\Data;

/**
 * The booking website line on the admin Overview. `webAddress` is null in API only mode, where the owner's own website
 * takes bookings. A property whose booking site was never opened is in Microsite mode and not published.
 */
class AdminPropertyBookingWebsiteData extends Data
{
    public function __construct(
        public BookingEngineMode $mode,
        public bool $published,
        public ?string $webAddress,
    ) {}

    public static function forProperty(Property $property, ?PropertyBookingSite $site): self
    {
        $mode = $site->mode ?? BookingEngineMode::Microsite;

        return new self(
            mode: $mode,
            published: $mode === BookingEngineMode::Microsite && ($site?->isPublished() ?? false),
            webAddress: $mode === BookingEngineMode::Microsite ? BookingWebsiteGeneralData::webAddressFor($property) : null,
        );
    }
}
