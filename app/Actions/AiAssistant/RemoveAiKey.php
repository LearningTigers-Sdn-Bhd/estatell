<?php

namespace App\Actions\AiAssistant;

use App\Models\Property;
use App\Models\User;
use App\Services\AiAssistant\AiKeyProvider;

/**
 * The hotel removes its OpenAI key. Every place of the assistant is off until a new key is saved. Returns false,
 * writing nothing, when there is no key. See docs/35-ai-assistant/01-overview.md#api-key.
 */
class RemoveAiKey
{
    public function __construct(private readonly AiKeyProvider $keys) {}

    public function __invoke(User $user, Property $property): bool
    {
        if (! $this->keys->hasKey($property)) {
            return false;
        }

        $property->settings->forceFill(['ai_openai_key' => null, 'ai_key_status' => null, 'ai_key_checked_at' => null])->save();

        activity()
            ->performedOn($property)
            ->causedBy($user)
            ->event('ai-assistant.key-removed')
            ->withProperties(['property_id' => $property->id])
            ->log('AI assistant OpenAI key removed');

        return true;
    }
}
