<?php

namespace App\Data\Reservations;

use App\Enums\Currency;
use Spatie\LaravelData\Data;

/**
 * The props of the group check-out page. `reference` and `reservationId` name the reservation in the breadcrumbs,
 * `preview` is the first answer (the page asks again 300 ms after a change) and `cancelUrl` is the reservation page.
 */
class GroupCheckOutPageData extends Data
{
    public function __construct(
        public int $reservationId,
        public string $reference,
        public string $title,
        public ?Currency $currency,
        public GroupCheckOutPreviewData $preview,
        public string $cancelUrl,
    ) {}
}
