<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiKeyStatus;
use App\Models\Property;

/**
 * OpenAI refused the hotel's key during a chat. Until the hotel tests the key again, turns make no model call and the
 * guest gets the handover message. Returns false, writing nothing, when the key is already Failing.
 * See docs/34-ai-assistant/01-overview.md#api-key.
 */
class MarkAiKeyFailing
{
    public function __invoke(Property $property): bool
    {
        $settings = $property->loadMissing('settings')->settings;

        if ($settings === null || $settings->ai_key_status === AiKeyStatus::Failing) {
            return false;
        }

        $settings->forceFill(['ai_key_status' => AiKeyStatus::Failing, 'ai_key_checked_at' => now()])->save();

        return true;
    }
}
