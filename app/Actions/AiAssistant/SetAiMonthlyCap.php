<?php

namespace App\Actions\AiAssistant;

use App\Models\Property;
use App\Models\User;

/**
 * The hotel sets the AI assistant's monthly spend limit, in micros of a US dollar, as it pays OpenAI. Null is no cap.
 * Returns false, writing nothing, when the cap is unchanged. See docs/35-ai-assistant/04-usage-and-cost.md#monthly-cap.
 */
class SetAiMonthlyCap
{
    public function __invoke(User $user, Property $property, ?int $capMicros): bool
    {
        $settings = $property->settings()->firstOrNew();
        $previous = $settings->ai_monthly_cap_micros;

        if ($settings->exists && $previous === $capMicros) {
            return false;
        }

        $settings->ai_monthly_cap_micros = $capMicros;
        $settings->save();

        activity()
            ->performedOn($property)
            ->causedBy($user)
            ->event('ai-assistant.cap-set')
            ->withProperties(['property_id' => $property->id, 'from' => $previous, 'to' => $capMicros])
            ->log('AI assistant monthly cap set');

        return true;
    }
}
