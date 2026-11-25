<?php

namespace App\Data\AiAssistant;

use App\Data\Shared\BadgeData;
use App\Enums\AiScope;
use App\Models\Property;
use App\Services\AiAssistant\AiKeyProvider;
use App\Services\AiAssistant\AiScopes;
use Spatie\LaravelData\Data;

/**
 * The AI assistant's child rows on the admin Features tab: the status of the hotel's OpenAI key (never the key) and the
 * Allowed switch of each released scope.
 */
class AiAssistantAdminData extends Data
{
    /**
     * @param  list<AiScopeData>  $scopes
     */
    public function __construct(
        public BadgeData $keyStatus,
        public array $scopes,
    ) {}

    public static function forProperty(Property $property): self
    {
        $aiScopes = app(AiScopes::class);
        $rows = $aiScopes->rows($property);

        return new self(
            keyStatus: BadgeData::forAiKey(app(AiKeyProvider::class)->hasKey($property), $property->settings?->ai_key_status),
            scopes: array_map(
                fn (AiScope $scope): AiScopeData => AiScopeData::forAdmin($scope, $rows->get($scope->value), $aiScopes->hasRequiredFeature($property, $scope)),
                $aiScopes->released(),
            ),
        );
    }
}
