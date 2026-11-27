<?php

namespace App\Services\Concierge;

use App\Data\Kiosk\KioskWalkInData;
use App\Models\ConciergeQuote;

/**
 * Reads AI quote links: the search a quote prices and books with, and the open link of an AI chat. The AI module
 * passes the chat's turn ids, as a quote knows only its turn. See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
class ConciergeQuotes
{
    /**
     * The stay a quote is for: its dates, adults and children's ages.
     */
    public function search(ConciergeQuote $quote): KioskWalkInData
    {
        $quote->loadMissing('children');

        return new KioskWalkInData(
            $quote->arrival_date,
            (int) $quote->arrival_date->diffInDays($quote->departure_date),
            $quote->adults,
            array_values($quote->children->sortBy('id')->map(fn ($child): int => $child->age)->all()),
        );
    }

    /**
     * The newest link of the chat that is not booked and not expired, or null.
     *
     * @param  list<int>  $aiTurnIds
     */
    public function openInChat(int $propertyId, array $aiTurnIds): ?ConciergeQuote
    {
        if ($aiTurnIds === []) {
            return null;
        }

        return ConciergeQuote::query()
            ->where('property_id', $propertyId)
            ->whereIn('ai_turn_id', $aiTurnIds)
            ->whereNull('reservation_id')
            ->where('expires_at', '>', now())
            ->with(['roomType', 'ratePlan'])
            ->latest('id')
            ->first();
    }
}
