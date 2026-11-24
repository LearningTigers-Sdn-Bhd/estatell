<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiKeyStatus;
use App\Models\Property;
use App\Models\User;

/**
 * The hotel saves its own OpenAI key, then it is tested at once. The key is stored encrypted and never logged. Returns
 * the test result: null when OpenAI could not be reached, so the key stays untested.
 * See docs/34-ai-assistant/01-overview.md#api-key.
 */
class SaveAiKey
{
    public function __construct(private readonly TestAiKey $testAiKey) {}

    public function __invoke(User $user, Property $property, string $key): ?AiKeyStatus
    {
        $settings = $property->settings()->firstOrNew();
        $settings->forceFill(['ai_openai_key' => trim($key), 'ai_key_status' => null, 'ai_key_checked_at' => null])->save();
        $property->setRelation('settings', $settings);

        activity()
            ->performedOn($property)
            ->causedBy($user)
            ->event('ai-assistant.key-saved')
            ->withProperties(['property_id' => $property->id])
            ->log('AI assistant OpenAI key saved');

        return ($this->testAiKey)($property);
    }
}
