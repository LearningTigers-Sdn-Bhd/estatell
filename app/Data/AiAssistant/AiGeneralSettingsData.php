<?php

namespace App\Data\AiAssistant;

use App\Models\Property;
use App\Services\AiAssistant\AiHandoverMessage;
use App\Services\AiAssistant\AiKeyProvider;
use App\Services\AiAssistant\AiMonthlyCap;
use Spatie\LaravelData\Data;

/**
 * The General tab of Settings › AI assistant: whether a key is saved (never the key), the monthly cap in US dollars,
 * and the handover message with the built-in text used when it is empty. See docs/34-ai-assistant/01-overview.md#settings.
 */
class AiGeneralSettingsData extends Data
{
    public function __construct(
        public bool $hasKey,
        public ?string $capUsd,
        public ?string $handoverMessage,
        public string $defaultHandoverMessage,
    ) {}

    public static function forProperty(Property $property): self
    {
        $settings = $property->loadMissing('settings')->settings;

        return new self(
            hasKey: app(AiKeyProvider::class)->hasKey($property),
            capUsd: app(AiMonthlyCap::class)->toDollars($settings?->ai_monthly_cap_micros),
            handoverMessage: $settings?->ai_handover_message,
            defaultHandoverMessage: app(AiHandoverMessage::class)->default(),
        );
    }
}
