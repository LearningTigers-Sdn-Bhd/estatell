<?php

namespace App\Services\AiAssistant;

use App\Enums\AiKeyStatus;
use App\Enums\AiOutcome;
use App\Models\Property;

/**
 * Whether a turn may call the model. At or over the monthly cap it may not (CapReached), nor while the hotel's key is
 * Failing (Failed): the guest then gets the handover message and the turn is logged with no tokens.
 * See docs/35-ai-assistant/04-usage-and-cost.md#monthly-cap and docs/35-ai-assistant/01-overview.md#api-key.
 */
class AiReplyGate
{
    public function __construct(private readonly AiMonthlyUsage $usage) {}

    /**
     * The outcome to log instead of calling the model, or null when the model may be called.
     */
    public function blockedOutcome(Property $property): ?AiOutcome
    {
        if ($this->usage->capReached($property)) {
            return AiOutcome::CapReached;
        }

        return $property->loadMissing('settings')->settings?->ai_key_status === AiKeyStatus::Failing ? AiOutcome::Failed : null;
    }
}
