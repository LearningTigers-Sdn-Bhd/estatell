<?php

namespace App\Data\Facilities;

use Spatie\LaravelData\Data;

/**
 * The New event page's quote: one answer per facility line, in the same order, and the totals for the summary rail.
 * `amount` is before tax (inclusive taxes stay inside it), `tax` the added taxes and `total` what the folio will be
 * charged. Lines charged by hand count nothing. See docs/33-facilities/03-events-and-event-folios.md#new-event-page.
 */
class EventLinesQuoteData extends Data
{
    /**
     * @param  list<FacilityBookingQuoteData>  $lines
     */
    public function __construct(
        public array $lines,
        public string $amount,
        public string $tax,
        public string $total,
    ) {}
}
