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
        return __("Good question! I've passed this to our front desk so they can help you personally. You can also contact them directly.");
    }

    public function forProperty(Property $property): string
    {
        $message = $property->settings?->ai_handover_message;

        return filled($message) ? $message : $this->default();
    }
}
