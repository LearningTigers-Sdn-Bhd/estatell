<?php

namespace App\Data\BookingEngine;

use App\Data\Shared\BadgeData;
use App\Enums\BookingEngineMode;
use App\Enums\PaymentMode;
use App\Enums\Tone;
use App\Models\PropertyBookingSite;
use Spatie\LaravelData\Data;

/**
 * The badge row under the Booking website header, shared on every tab: the publish status, the mode, and a Test
 * payments badge while the site takes test payments. A site that was never opened is not published and in Microsite mode.
 */
class BookingWebsiteHeaderData extends Data
{
    public function __construct(
        public BadgeData $status,
        public BadgeData $mode,
        public ?BadgeData $testPayments,
    ) {}

    public static function forSite(?PropertyBookingSite $site): self
    {
        return new self(
            status: $site?->isPublished() === true
                ? new BadgeData('published', __('Published'), Tone::Success)
                : new BadgeData('not-published', __('Not published'), Tone::Neutral),
            mode: BadgeData::fromBookingEngineMode($site->mode ?? BookingEngineMode::Microsite),
            testPayments: $site?->payment_mode === PaymentMode::Test ? BadgeData::fromPaymentMode(PaymentMode::Test) : null,
        );
    }
}
