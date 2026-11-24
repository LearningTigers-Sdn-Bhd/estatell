<?php

use App\Enums\AiKeyStatus;
use App\Models\Property;

/**
 * Save a hotel OpenAI key on the property, so its AI assistant scopes can be on. Tests never call OpenAI: they fake
 * the agent or the HTTP client.
 */
function giveAiKey(Property $property, ?AiKeyStatus $status = AiKeyStatus::Working, string $key = 'sk-test-hotel-1234'): void
{
    $settings = $property->settings()->firstOrNew();
    $settings->forceFill(['ai_openai_key' => $key, 'ai_key_status' => $status, 'ai_key_checked_at' => $status === null ? null : now()])->save();
    $property->setRelation('settings', $settings);
}
