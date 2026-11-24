<?php

namespace App\Services\AiAssistant;

use App\Models\Property;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\InvalidApiKeyException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Providers\Provider;
use LogicException;
use Throwable;

/**
 * The OpenAI provider on the hotel's own key. Each hotel brings its own key (BYOK) and Estatell has none, so there is no
 * fallback. The provider uses the model and options of `ai.providers.openai`, with the hotel's key in place of the env
 * key. See docs/34-ai-assistant/01-overview.md#api-key.
 */
class AiKeyProvider
{
    /**
     * Whether the property has saved a key. Reads the stored value, so the key is not decrypted.
     */
    public function hasKey(Property $property): bool
    {
        return $property->loadMissing('settings')->settings?->getRawOriginal('ai_openai_key') !== null;
    }

    /**
     * The OpenAI provider on the property's key, built for this process.
     *
     * @throws LogicException when the property has no key
     */
    public function for(Property $property): Provider
    {
        $key = $property->loadMissing('settings')->settings?->ai_openai_key;

        if (blank($key)) {
            throw new LogicException("Property [{$property->id}] has no OpenAI key.");
        }

        return Ai::build([
            ...config('ai.providers.openai'),
            'name' => "openai-property-{$property->id}",
            'key' => $key,
        ]);
    }

    /**
     * Whether OpenAI refused the key itself: a wrong key (401), or an account with no credit. Other errors, such as
     * OpenAI being down, say nothing about the key.
     */
    public function refusesKey(Throwable $exception): bool
    {
        return $exception instanceof InvalidApiKeyException
            || $exception instanceof InsufficientCreditsException
            || ($exception instanceof StreamErrorException && $exception->error?->type === 'insufficient_quota');
    }
}
