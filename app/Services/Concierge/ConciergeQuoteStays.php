<?php

namespace App\Services\Concierge;

use App\Models\ConciergeQuote;
use App\Models\ReservationUnit;

/**
 * Whether a stay was booked from an AI quote link. Such a stay pays the whole stay up front, so its Payment step asks for
 * the whole bill and is worded as a booking. Reads only. See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
class ConciergeQuoteStays
{
    public function isQuoted(ReservationUnit $stay): bool
    {
        return ConciergeQuote::query()->where('property_id', $stay->property_id)->where('reservation_id', $stay->reservation_id)->exists();
    }
}
