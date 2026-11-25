<?php

namespace App\Data\AiAssistant;

use App\Data\Shared\BadgeData;
use App\Enums\AiScope;
use App\Enums\AiScopeKind;
use App\Models\Property;
use App\Services\AiAssistant\AiKeyProvider;
use App\Services\AiAssistant\AiMonthlyCap;
use App\Services\AiAssistant\AiMonthlyUsage;
use App\Services\AiAssistant\AiScopes;
use Spatie\LaravelData\Data;

/**
 * The status rail on every tab of Settings › AI assistant: the hotel's key (masked to its last four characters), its
 * last test, how many places are on, and this month's estimated spend against the cap. Amounts are US dollars, as
 * OpenAI bills. See docs/35-ai-assistant/01-overview.md#settings.
 */
class AiAssistantStatusData extends Data
{
    public function __construct(
        public ?string $maskedKey,
        public BadgeData $keyStatus,
        public ?string $checkedAt,
        public int $placesOn,
        public int $placesTotal,
        public string $spentUsd,
        public ?string $capUsd,
        public ?int $capPercent,
    ) {}

    public static function forProperty(Property $property): self
    {
        $settings = $property->loadMissing('settings')->settings;
        $hasKey = app(AiKeyProvider::class)->hasKey($property);
        $scopes = app(AiScopes::class);
        $places = array_values(array_filter($scopes->forHotel($property), fn (AiScope $scope): bool => $scope->kind() === AiScopeKind::Place));
        $cap = app(AiMonthlyCap::class);
        $spentMicros = $settings === null ? 0 : app(AiMonthlyUsage::class)->micros($property);
        $capMicros = $settings?->ai_monthly_cap_micros;

        return new self(
            maskedKey: $hasKey ? 'sk-…'.substr((string) $settings?->ai_openai_key, -4) : null,
            keyStatus: BadgeData::forAiKey($hasKey, $settings?->ai_key_status),
            checkedAt: $settings?->ai_key_checked_at?->toIso8601String(),
            placesOn: count(array_filter($places, fn (AiScope $scope): bool => $scopes->isOn($property, $scope))),
            placesTotal: count($places),
            spentUsd: (string) $cap->toDollars($spentMicros),
            capUsd: $cap->toDollars($capMicros),
            capPercent: $capMicros === null ? null : min(100, intdiv($spentMicros * 100, $capMicros)),
        );
    }
}
