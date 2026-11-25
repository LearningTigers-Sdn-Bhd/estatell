<?php

namespace App\Data\AiAssistant;

use App\Enums\AiScope;
use App\Models\Property;
use App\Services\AiAssistant\AiMonthlyCap;
use App\Services\AiAssistant\AiScopes;
use Spatie\LaravelData\Data;

/**
 * The AI assistant's child rows on the admin Features tab: the monthly cap in US dollars and the Allowed switch of each
 * scope.
 */
class AiAssistantAdminData extends Data
{
    /**
     * @param  list<AiScopeData>  $scopes
     */
    public function __construct(
        public ?string $capUsd,
        public array $scopes,
    ) {}

    public static function forProperty(Property $property): self
    {
        $aiScopes = app(AiScopes::class);
        $rows = $aiScopes->rows($property);

        return new self(
            capUsd: app(AiMonthlyCap::class)->toDollars($property->settings?->ai_monthly_cap_micros),
            scopes: array_map(
                fn (AiScope $scope): AiScopeData => AiScopeData::forAdmin($scope, $rows->get($scope->value), $aiScopes->hasRequiredFeature($property, $scope)),
                AiScope::cases(),
            ),
        );
    }
}
