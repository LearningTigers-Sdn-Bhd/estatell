<?php

namespace App\Actions\Concierge;

use App\Models\ConciergeQuote;

/**
 * Link a quote to the AI turn that sent it, once that turn is logged. The turn is written after the reply ends, so the
 * quote cannot know it when it is made. Only a quote of the same property with no turn yet is changed.
 */
class AttachConciergeQuoteToAiTurn
{
    public function __invoke(int $propertyId, int $quoteId, int $aiTurnId): void
    {
        ConciergeQuote::query()
            ->where('property_id', $propertyId)
            ->whereKey($quoteId)
            ->whereNull('ai_turn_id')
            ->update(['ai_turn_id' => $aiTurnId]);
    }
}
