<?php

namespace App\Actions\Concierge;

use App\Models\ConciergeQuote;

/**
 * End the earlier open quotes of an AI chat once a new quote replaces them, so the guest cannot book old dates from an
 * old link. The AI module passes the ids of the chat's turns, as a quote knows only its turn. A quote that is booked or
 * already expired is left alone. The old link then shows the expired message. Returns how many quotes were ended.
 * See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
class ReplaceConciergeQuotes
{
    /**
     * @param  list<int>  $aiTurnIds
     */
    public function __invoke(int $propertyId, array $aiTurnIds, int $newQuoteId): int
    {
        if ($aiTurnIds === []) {
            return 0;
        }

        return ConciergeQuote::query()
            ->where('property_id', $propertyId)
            ->whereIn('ai_turn_id', $aiTurnIds)
            ->whereKeyNot($newQuoteId)
            ->whereNull('reservation_id')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);
    }
}
