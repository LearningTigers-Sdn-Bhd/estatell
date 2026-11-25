<?php

namespace App\Services\AiAssistant;

use App\Models\Property;

/**
 * What the AI assistant tells a guest when it hands over to staff: the property's own message, or the built-in text.
 */
class AiHandoverMessage
{
    public function default(): string
    {
        return __("I'll pass this to our front desk. They will reply soon.");
    }

    public function forProperty(Property $property): string
    {
        $message = $property->settings?->ai_handover_message;

        return filled($message) ? $message : $this->default();
    }
}
