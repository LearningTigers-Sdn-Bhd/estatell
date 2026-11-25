<?php

namespace App\Actions\AiAssistant;

use App\Models\Property;
use App\Models\User;

/**
 * Save what the AI assistant tells a guest when it hands over to staff. Blank clears it, so the built-in text is used.
 * Nothing is written or logged when it is unchanged.
 */
class SaveAiHandoverMessage
{
    public function __invoke(User $actor, Property $property, ?string $message): void
    {
        $settings = $property->settings()->firstOrNew();
        $settings->ai_handover_message = filled($message) ? trim((string) $message) : null;

        if ($settings->exists && ! $settings->isDirty('ai_handover_message')) {
            return;
        }

        $settings->save();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('ai-assistant.handover-message-saved')
            ->withProperties(['property_id' => $property->id])
            ->log('AI assistant handover message saved');
    }
}
