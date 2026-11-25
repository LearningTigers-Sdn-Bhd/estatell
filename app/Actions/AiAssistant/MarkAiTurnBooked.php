<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiOutcome;
use App\Events\ConciergeQuoteBooked;
use App\Models\AiTurn;

/**
 * A quote link became a booking: the turn that sent it gets the reservation and the outcome Booked. A quote with no turn
 * changes nothing. See docs/35-ai-assistant/04-usage-and-cost.md#data-model.
 */
class MarkAiTurnBooked
{
    public function __invoke(ConciergeQuoteBooked $event): void
    {
        if ($event->aiTurnId === null) {
            return;
        }

        AiTurn::query()
            ->where('property_id', $event->propertyId)
            ->whereKey($event->aiTurnId)
            ->update(['reservation_id' => $event->reservationId, 'outcome' => AiOutcome::Booked]);
    }
}
