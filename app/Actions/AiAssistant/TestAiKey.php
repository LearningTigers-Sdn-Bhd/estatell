<?php

namespace App\Actions\AiAssistant;

use App\Ai\Agents\OpenAiKeyCheck;
use App\Enums\AiKeyStatus;
use App\Models\Property;
use App\Services\AiAssistant\AiKeyProvider;
use Throwable;

/**
 * Test the hotel's saved OpenAI key with one small call, and save the result: Working, or Failing when OpenAI refuses
 * the key (wrong key, or no credit). Returns null, writing nothing, when there is no key or the call failed for another
 * reason (OpenAI is down), which says nothing about the key. See docs/35-ai-assistant/01-overview.md#api-key.
 */
class TestAiKey
{
    public function __construct(private readonly AiKeyProvider $keys) {}

    public function __invoke(Property $property): ?AiKeyStatus
    {
        if (! $this->keys->hasKey($property)) {
            return null;
        }

        try {
            OpenAiKeyCheck::make()->prompt('OK', provider: $this->keys->for($property)->name());
            $status = AiKeyStatus::Working;
        } catch (Throwable $exception) {
            if (! $this->keys->refusesKey($exception)) {
                report($exception);

                return null;
            }

            $status = AiKeyStatus::Failing;
        }

        $property->settings->forceFill(['ai_key_status' => $status, 'ai_key_checked_at' => now()])->save();

        return $status;
    }
}
