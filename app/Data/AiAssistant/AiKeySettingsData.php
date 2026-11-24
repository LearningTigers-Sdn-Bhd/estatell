<?php

namespace App\Data\AiAssistant;

use App\Data\Shared\BadgeData;
use App\Models\Property;
use App\Services\AiAssistant\AiKeyProvider;
use App\Services\AiAssistant\AiMonthlyCap;
use Spatie\LaravelData\Data;

/**
 * The API key tab of Settings › AI assistant: the hotel's OpenAI key, masked to its last four characters, whether it
 * works, and the monthly cap in US dollars. The full key never reaches the browser.
 * See docs/34-ai-assistant/01-overview.md#api-key.
 */
class AiKeySettingsData extends Data
{
    public function __construct(
        public ?string $maskedKey,
        public BadgeData $status,
        public ?string $checkedAt,
        public ?string $capUsd,
    ) {}

    public static function forProperty(Property $property): self
    {
        $settings = $property->loadMissing('settings')->settings;
        $hasKey = app(AiKeyProvider::class)->hasKey($property);

        return new self(
            maskedKey: $hasKey ? 'sk-…'.substr((string) $settings->ai_openai_key, -4) : null,
            status: BadgeData::forAiKey($hasKey, $settings?->ai_key_status),
            checkedAt: $settings?->ai_key_checked_at?->toIso8601String(),
            capUsd: app(AiMonthlyCap::class)->toDollars($settings?->ai_monthly_cap_micros),
        );
    }
}
